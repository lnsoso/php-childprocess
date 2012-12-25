<?php

namespace CodeGun\Fork;

declare(ticks = 1) ;

class ChildProcess extends EventEmitter
{
    public $pid;

    protected $process;

    /**
     * @var Process[]
     */
    protected $children;

    public function __construct()
    {
        $this->pid = posix_getpid();
        $this->process = new Process($this->pid);
        $this->registerSigHandlers();
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     *
     * @param callable $callback
     * @return Process
     * @throws \RuntimeException
     */
    public function parallel($callback)
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child worker.');
        } else if ($pid) {
            $this->children[$pid] = new Process($pid);
            return $this->children[$pid];
        } else {
            if (is_callable($callback)) {
                call_user_func_array($callback, array($this->process));
            } else {
                throw new \RuntimeException('Only callable can be callback');
            }
            exit;
        }
    }

    /**
     * Register signal handlers that a worker should respond to.
     *
     * TERM: Shutdown immediately and stop processing jobs.
     * INT: Shutdown immediately and stop processing jobs.
     * QUIT: Shutdown after the current job finishes processing.
     * USR1: Kill the forked child immediately and continue processing jobs.
     */
    protected function registerSigHandlers()
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, array($this, 'signalHandler'));
        pcntl_signal(SIGINT, array($this, 'signalHandler'));
        pcntl_signal(SIGQUIT, array($this, 'signalHandler'));
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'));
        pcntl_signal(SIGUSR2, array($this, 'signalHandler'));
        pcntl_signal(SIGCONT, array($this, 'signalHandler'));
        pcntl_signal(SIGPIPE, array($this, 'signalHandler'));
        pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
    }

    /**
     * Control signals
     *
     * @param $signal
     */
    public function signalHandler($signal)
    {
        $this->emit($signal);
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->emit('shutdown');
                break;
            case SIGQUIT:
                $this->emit('quit');
                break;
            case SIGCHLD:
                while (($pid = pcntl_wait($status)) > 0) {
                    $this->children[$pid]->emit('exit', $status);
                    $this->children[$pid]->status = $status;
                }
        }
    }
}