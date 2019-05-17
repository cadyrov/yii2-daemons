<?php
namespace cadyrov\daemons\controllers;
use cadyrov\daemons\Daemon;

abstract class ObserverController extends Daemon
{
    public $daemonFolder = 'daemons';
    protected $firstIteration = true;
    public function init()
    {
        $pid_file = $this->getPidPath();
        if (file_exists($pid_file) && ($pid = file_get_contents($pid_file)) && file_exists("/proc/$pid")) {
            $this->halt(self::EXIT_CODE_ERROR, 'Another observer is already running.');
        }
        parent::init();
    }

    protected function doJob($job)
    {
        $pid_file = $this->getPidPath($job['daemon']);
        \Yii::trace('Check daemon ' . $job['daemon']);
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            if ($this->isProcessRunning($pid)) {
                if ($job['enabled']) {
                    \Yii::trace('Daemon ' . $job['daemon'] . ' running and working fine');
                    return true;
                } else {
                    \Yii::warning('Daemon ' . $job['daemon'] . ' running, but disabled in config. Send SIGTERM signal.');
                    if (isset($job['hardKill']) && $job['hardKill']) {
                        posix_kill($pid, SIGKILL);
                    } else {
                        posix_kill($pid, SIGTERM);
                    }
                    return true;
                }
            }
        }
        \Yii::error('Daemon pid not found.');
        if ($job['enabled']) {
            \Yii::trace('Try to run daemon ' . $job['daemon'] . '.');
            $command_name = $job['daemon'] . DIRECTORY_SEPARATOR . 'index';
            //flush log before fork
            $this->flushLog(true);
            //run daemon
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->halt(self::EXIT_CODE_ERROR, 'pcntl_fork() returned error');
            } elseif ($pid === 0) {
                $this->cleanLog();
                \Yii::$app->requestedRoute = $command_name;
                \Yii::$app->runAction("$command_name", ['demonize' => 1]);
                $this->halt(0);
            } else {
                $this->initLogger();
                \Yii::trace('Daemon ' . $job['daemon'] . ' is running with pid ' . $pid);
            }
        }
        \Yii::trace('Daemon ' . $job['daemon'] . ' is checked.');
        return true;
    }

    protected function defineJobs()
    {
        if ($this->firstIteration) {
            $this->firstIteration = false;
        } else {
            sleep($this->sleep);
        }
        return $this->getDaemonsList();
    }

    abstract protected function getDaemonsList();

    public function isProcessRunning($pid)
    {
        return file_exists("/proc/$pid");
    }
}
