<?php
namespace cadyrov\daemons;
use yii\base\NotSupportedException;
use yii\console\Controller;
use yii\helpers\Console;


abstract class Daemon extends Controller
{
    const EVENT_BEFORE_JOB = "EVENT_BEFORE_JOB";
    const EVENT_AFTER_JOB = "EVENT_AFTER_JOB";
    const EVENT_BEFORE_ITERATION = "event_before_iteration";
    const EVENT_AFTER_ITERATION = "event_after_iteration";

    public $demonize = false;
    public $isMultiInstance = false;
    public $maxChildProcesses = 10;

    protected $parentPID;
    protected static $currentJobs = [];
    protected $memoryLimit = 268435456;
    protected $sleep = 5;
    protected $pidDir = "@runtime/daemons/pids";
    protected $logDir = "@runtime/daemons/logs";

    private $stdIn;
    private $stdOut;
    private $stdErr;
    private static $stopFlag = false;

    public function init()
    {
        parent::init();
        pcntl_signal(SIGTERM, ['cadyrov\daemons\Daemon', 'signalHandler']);
        pcntl_signal(SIGINT, ['cadyrov\daemons\Daemon', 'signalHandler']);
        pcntl_signal(SIGHUP, ['cadyrov\daemons\Daemon', 'signalHandler']);
        pcntl_signal(SIGUSR1, ['cadyrov\daemons\Daemon', 'signalHandler']);
        pcntl_signal(SIGCHLD, ['cadyrov\daemons\Daemon', 'signalHandler']);
    }

    function __destruct()
    {
        $this->deletePid();
    }

    protected function initLogger()
    {
        $targets = \Yii::$app->getLog()->targets;
        foreach ($targets as $name => $target) {
            $target->enabled = false;
        }
        $config = [
            'levels' => ['error', 'warning', 'trace', 'info'],
            'logFile' => \Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->getProcessName() . '.log',
            'logVars' => [],
            'except' => [
                'yii\db\*',
            ],
        ];
        $targets['daemon'] = new \yii\log\FileTarget($config);
        \Yii::$app->getLog()->targets = $targets;
        \Yii::$app->getLog()->init();
    }

    abstract protected function doJob($job);

    final public function actionIndex()
    {
        if ($this->demonize) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->halt(self::EXIT_CODE_ERROR, 'pcntl_fork() error');
            } elseif ($pid) {
                $this->cleanLog();
                $this->halt(self::EXIT_CODE_NORMAL);
            } else {
                posix_setsid();
                $this->closeStdStreams();
            }
        }
        $this->changeProcessName();
        return $this->loop();
    }


    protected function changeProcessName()
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            cli_set_process_title($this->getProcessName());
        } else {
            if (function_exists('setproctitle')) {
                setproctitle($this->getProcessName());
            } else {
                \Yii::error('Can\'t find cli_set_process_title or setproctitle function');
            }
        }
    }

    protected function closeStdStreams()
    {
        if (is_resource(STDIN)) {
            fclose(STDIN);
            $this->stdIn = fopen('/dev/null', 'r');
        }
        if (is_resource(STDOUT)) {
            fclose(STDOUT);
            $this->stdOut = fopen('/dev/null', 'ab');
        }
        if (is_resource(STDERR)) {
            fclose(STDERR);
            $this->stdErr = fopen('/dev/null', 'ab');
        }
    }

    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            $this->initLogger();
            if ($action->id != "index") {
                throw new NotSupportedException(
                    "Only index action allowed in daemons."
                );
            }
            return true;
        } else {
            return false;
        }
    }

    public function options($actionID)
    {
        return [
            'demonize',
            'taskLimit',
            'isMultiInstance',
            'maxChildProcesses',
        ];
    }

    abstract protected function defineJobs();

    protected function defineJobExtractor(&$jobs)
    {
        return array_shift($jobs);
    }

    final private function loop()
    {
        if (file_put_contents($this->getPidPath(), getmypid())) {
            $this->parentPID = getmypid();
            \Yii::trace('Daemon ' . $this->getProcessName() . ' pid ' . getmypid() . ' started.');
            while (!self::$stopFlag) {
                if (memory_get_usage() > $this->memoryLimit) {
                    \Yii::trace('Daemon ' . $this->getProcessName() . ' pid ' .
                        getmypid() . ' used ' . memory_get_usage() . ' bytes on ' . $this->memoryLimit .
                        ' bytes allowed by memory limit');
                    break;
                }
                $this->trigger(self::EVENT_BEFORE_ITERATION);
                $this->renewConnections();
                $jobs = $this->defineJobs();
                if ($jobs && !empty($jobs)) {
                    while (($job = $this->defineJobExtractor($jobs)) !== null) {
                        if ($this->isMultiInstance && (count(static::$currentJobs) >= $this->maxChildProcesses)) {
                            \Yii::trace('Reached maximum number of child processes. Waiting...');
                            while (count(static::$currentJobs) >= $this->maxChildProcesses) {
                                sleep(1);
                                pcntl_signal_dispatch();
                            }
                            \Yii::trace(
                                'Free workers found: ' .
                                ($this->maxChildProcesses - count(static::$currentJobs)) .
                                ' worker(s). Delegate tasks.'
                            );
                        }
                        pcntl_signal_dispatch();
                        $this->runDaemon($job);
                    }
                } else {
                    sleep($this->sleep);
                }
                pcntl_signal_dispatch();
                $this->trigger(self::EVENT_AFTER_ITERATION);
            }
            \Yii::info('Daemon ' . $this->getProcessName() . ' pid ' . getmypid() . ' is stopped.');
            return self::EXIT_CODE_NORMAL;
        }
        $this->halt(self::EXIT_CODE_ERROR, 'Can\'t create pid file ' . $this->getPidPath());
    }

    protected function deletePid()
    {
        $pid = $this->getPidPath();
        if (file_exists($pid)) {
            if (file_get_contents($pid) == getmypid()) {
                unlink($this->getPidPath());
            }
        } else {
            \Yii::error('Can\'t unlink pid file ' . $this->getPidPath());
        }
    }

    final static function signalHandler($signo, $pid = null, $status = null)
    {
        switch ($signo) {
            case SIGINT:
            case SIGTERM:
                self::$stopFlag = true;
                break;
            case SIGHUP:
                break;
            case SIGUSR1:
                break;
            case SIGCHLD:
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                while ($pid > 0) {
                    if ($pid && isset(static::$currentJobs[$pid])) {
                        unset(static::$currentJobs[$pid]);
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                break;
        }
    }

    final public function runDaemon($job)
    {
        if ($this->isMultiInstance) {
            $this->flushLog();
            $pid = pcntl_fork();
            if ($pid == -1) {
                return false;
            } elseif ($pid !== 0) {
                static::$currentJobs[$pid] = true;
                return true;
            } else {
                $this->cleanLog();
                $this->renewConnections();
                //child process must die
                $this->trigger(self::EVENT_BEFORE_JOB);
                $status = $this->doJob($job);
                $this->trigger(self::EVENT_AFTER_JOB);
                if ($status) {
                    $this->halt(self::EXIT_CODE_NORMAL);
                } else {
                    $this->halt(self::EXIT_CODE_ERROR, 'Child process #' . $pid . ' return error.');
                }
            }
        } else {
            $this->trigger(self::EVENT_BEFORE_JOB);
            $status = $this->doJob($job);
            $this->trigger(self::EVENT_AFTER_JOB);
            return $status;
        }
    }

    protected function halt($code, $message = null)
    {
        if ($message !== null) {
            if ($code == self::EXIT_CODE_ERROR) {
                \Yii::error($message);
                if (!$this->demonize) {
                    $message = Console::ansiFormat($message, [Console::FG_RED]);
                }
            } else {
                \Yii::trace($message);
            }
            if (!$this->demonize) {
                $this->writeConsole($message);
            }
        }
        if ($code !== -1) {
            \Yii::$app->end($code);
        }
    }

    protected function renewConnections()
    {
        if (isset(\Yii::$app->db)) {
            \Yii::$app->db->close();
            \Yii::$app->db->open();
        }
    }

    private function writeConsole($message)
    {
        $out = Console::ansiFormat('[' . date('d.m.Y H:i:s') . '] ', [Console::BOLD]);
        $this->stdout($out . $message . "\n");
    }

    public function getPidPath($daemon = null)
    {
        $dir = \Yii::getAlias($this->pidDir);
        if (!file_exists($dir)) {
            mkdir($dir, 0744, true);
        }
        $daemon = $this->getProcessName($daemon);
        return $dir . DIRECTORY_SEPARATOR . $daemon;
    }

    public function getProcessName($route = null)
    {
        if (is_null($route)) {
            $route = \Yii::$app->requestedRoute;
        }
        return str_replace(['/index', '/'], ['', '.'], $route);
    }

    public function stdout($string)
    {
        if (!$this->demonize && is_resource(STDOUT)) {
            return parent::stdout($string);
        } else {
            return false;
        }
    }

    public function stderr($string)
    {
        if (!$this->demonize && is_resource(\STDERR)) {
            return parent::stderr($string);
        } else {
            return false;
        }
    }

    protected function cleanLog()
    {
        \Yii::$app->log->logger->messages = [];
    }

    protected function flushLog($final = false)
    {
        \Yii::$app->log->logger->flush($final);
    }
}
