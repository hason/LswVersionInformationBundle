<?php
namespace Lsw\VersionInformationBundle\DataCollector;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

/**
 * VersionInformationDataCollector
 *
 * @author Maurits van der Schee <m.vanderschee@leaseweb.com>
 */
class VersionInformationDataCollector extends DataCollector
{

    private $rootDir;

    const SVN = 'svn';
    const GIT = 'git';

    /**
     * Class constructor
     *
     * @param KernelInterface $kernel Kernel object
     */
    public function __construct($rootDir)
    {
        $this->rootDir = $rootDir;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Request $request, Response $response, \Exception $exception = null)
    {
        if (isset($this->data)) {
            return;
        }

        $this->data = (object) array();

        if (file_exists($this->rootDir . '/.svn/')) {
            $this->data->mode = self::SVN;
            $this->collectSvn($this->rootDir, $request, $response);
        } elseif (file_exists($this->rootDir . '/.git/')) {
            $this->data->mode = self::GIT;
            $this->collectGit($this->rootDir, $request, $response);
        } else {
            throw new \InvalidArgumentException(sprintf('Could not find Subversion or Git repository in "%s" directory.', $this->rootDir));
        }
    }

    private function runCommand($command)
    {
        $process = new Process($command, $this->rootDir);

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $env = array_change_key_case(array_replace($_ENV, $_SERVER), CASE_UPPER);
            $process->setEnv(array(
                'PATH' => $env['PATH'],
                'SYSTEMROOT' => $env['SYSTEMROOT'],
            ));
        }

        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }
        
        return $process->getOutput();
    }

    private function collectGit($rootDir, Request $request, Response $response)
    {
        $currentBranch = trim($this->runCommand('git rev-parse --abbrev-ref HEAD'));
        $refs = explode("\n",trim($this->runCommand('git --no-pager show-ref --dereference')));

        $head = substr($refs[0],41);
        foreach ($refs as $ref) {
            if (strstr($ref, $currentBranch)) {
                $head = substr($ref,41);
                break;
            }
        }
        foreach ($refs as $ref) {
            $remote = substr($ref,41);
            if (stripos($remote,'origin')!==false && stripos($remote,'master')!==false) {
                break;
            }
        }
        $ahead = "$head..$remote";
        $behind = "$remote..$head";

        $this->data->information = json_encode($this->runCommand('git --no-pager log -1 --pretty={"hash":"%h","date":"%ai","name":"%an","branch":"%d"}'));
        $this->data->informationText = $this->runCommand('git --no-pager log -1 --decorate');

        $this->data->statusText = $this->runCommand('git --no-pager status --porcelain');
        $this->data->status = explode("\n", trim($this->data->statusText));

        $output = trim($this->runCommand('git --no-pager log --pretty=format: '.$ahead.' --name-status'));
        $this->data->ahead = $output ? explode("\n", trim($output)) : array();
        $this->data->ahead = array_filter($this->data->ahead);

        $this->data->aheadText = trim($this->runCommand('git --no-pager log '.$ahead.' --name-status'));
        $this->data->behind = array_filter(explode("\n", trim($this->runCommand('git --no-pager log --pretty=format: '.$behind.' --name-status '))));
        $this->data->behindText = $this->runCommand('git --no-pager log '.$behind.' --name-status ');

    }

    private function collectSvn($rootDir, Request $request, Response $response)
    {
        $output = $this->runCommand('svn info --xml');
        $this->data->information = json_decode(json_encode(simplexml_load_string($output)));
        $this->data->informationText = $this->runCommand('svn info');

        $output = $this->runCommand('svn status --xml');
        $this->data->status = json_decode(json_encode(simplexml_load_string($output)));
        $this->data->statusText = $this->runCommand('svn status');
    }

    /**
     * Get the string 'svn' or 'git', depending on the mode
     *
     * @return string
     */
    public function getMode()
    {
        return $this->data->mode;
    }

    /**
     * Get the last revision number from svn info
     *
     * @return number
     */
    public function getRevision()
    {
        if ($this->data->mode == self::SVN) {
            return $this->data->information->entry->commit->{'@attributes'}
                    ->revision;
        } elseif ($this->data->mode == self::GIT) {
            return $this->data->information->hash;
        }
    }

    /**
     * Get the last author from svn info
     *
     * @return string
     */
    public function getAuthor()
    {
        if ($this->data->mode == self::SVN) {
            return $this->data->information->entry->commit->author;
        } elseif ($this->data->mode == self::GIT) {
            return $this->data->information->name;
        }
    }

    /**
     * Get the branche from svn info
     *
     * @return string
     */
    public function getBranch()
    {
        if ($this->data->mode == self::SVN) {
            return str_replace(
                    $this->data->information->entry->repository->root, '',
                    $this->data->information->entry->url);
        } elseif ($this->data->mode == self::GIT) {
            return $this->data->information->branch;
        }
    }

    /**
     * Get the last modified date from svn info
     *
     * @return date
     */
    public function getDate()
    {
        if ($this->data->mode == self::SVN) {
            return strtotime($this->data->information->entry->commit->date);
        } elseif ($this->data->mode == self::GIT) {
            return $this->data->information->date;
        }
    }

    /**
     * Get the number of dirty files from svn status
     *
     * @return number
     */
    public function getDirtyCount()
    {
        if ($this->data->mode == self::SVN) {
            if (!isset($this->data->status->target->entry)) {
                return 0;
            }

            return count($this->data->status->target->entry);
        } elseif ($this->data->mode == self::GIT) {
            return count($this->data->status);
        }

    }

    /**
     * Get the number of commits ahead from git log
     *
     * @return number
     */
    public function getAheadCount()
    {
        if ($this->data->mode == self::GIT) {
            return count($this->data->ahead);
        }

        return 0;
    }

    /**
     * Get the number of commits behind from git log
     *
     * @return number
     */
    public function getBehindCount()
    {
        if ($this->data->mode == self::GIT) {
            return count($this->data->behind);
        }

        return 0;
    }

    /**
     * Get the svn info output
     *
     * @return string
     */
    public function getInformationText()
    {
        return $this->data->informationText;
    }

    /**
     * Get the svn status output
     *
     * @return string
     */
    public function getStatusText()
    {
        return $this->data->statusText;
    }

    /**
     * Get the git log ahead output
     *
     * @return string
     */
    public function getAheadText()
    {
        return $this->data->aheadText;
    }

    /**
     * Get the git log behind output
     *
     * @return string
     */
    public function getBehindText()
    {
        return $this->data->behindText;
    }
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'version_information';
    }
}
