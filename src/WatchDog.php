<?php
/*
 * (c) 2015 by Dennis Birkholz / nexxes Informationstechnik GmbH
 * All rights reserved.
 * For the license to use this software, see the LICENSE file provided with this package.
 */

namespace nexxes;

/**
 * Do the work inside a child process and restart this process on failure
 *
 * @author Dennis Birkholz <dennis.birkholz@nexxes.net>
 */
class WatchDog
{
    /**
     * Map of process identifiers to callbacks
     * @var callable[]
     */
    protected $processCallbacks = [];

    /**
     * Map of process identifiers to restart timeouts
     * @var int[]
     */
    protected $processTimeouts = [];

    /**
     * Map of PIDs of forked children to process identifiers
     * @var string[]
     */
    protected $processPIDs = [];

    /**
     * Map of process identifiers to start timestamps
     *
     * @var float[]
     */
    protected $processStarted = [];

    /**
     * Map of process identifiers to stop timestamps if process finished gracefully
     *
     * @var float[]
     */
    protected $processFinished = [];

    /**
     * Map of process identifiers to desired restart times
     *
     * @var float[]
     */
    protected $processRestart = [];

    /**
     * Map of process identifiers to started instances counter
     *
     * @var int[]
     */
    protected $processStartCounter = [];



    /**
     * Register a new process that should be started.
     *
     * @param string $name Unique identifier for the new process
     * @param callable $callback Callback that will be executed after forking
     * @return $this
     */
    public function addProcess($name, callable $callback)
    {
        if (isset($this->processCallbacks[$name])) {
            throw new \InvalidArgumentException("Process '$name' is already registered.");
        }

        $this->processCallbacks[$name] = $callback;
        return $this;
    }

    /**
     * Set the restart timeout for the named process
     *
     * @param string $name
     * @param int $timeout
     * @return $this
     */
    public function setTimeout($name, $timeout)
    {
        if (!isset($this->processCallbacks[$name])) {
            throw new \InvalidArgumentException("Process '$name' not registered.");
        }

        $this->processTimeouts[$name] = $timeout;
        return $this;
    }

    /**
     * Start and monitor all registered processes.
     *
     * @param bool $returnWhenFinished Return true instead of exit(0) when all processes exited gracefully
     * @return bool True in parent and False in child
     */
    public function start($returnWhenFinished = false)
    {
        // Initial startup
        foreach ($this->processCallbacks as $name => $callback) {
            // If forkChild() returns false, we are inside the child here
            if (!$this->forkChild($name, $callback)) {
                return false;
            }
        }

        // Handle signals, prevent process from just dying on INT/TERM signal
        \pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD, SIGTERM, SIGINT]);

        while (true) {
            $signalInfo = null;
            $signal = \pcntl_sigtimedwait([SIGCHLD, SIGTERM, SIGINT], $signalInfo, 5);

            // Ignore timeout
            if ($signal === -1) {
            }

            // Master process is signaled to exit
            elseif (($signal === SIGTERM) || ($signal === SIGINT)) {
                $this->killChildren($signal);
                break;
            }

            // Child died
            elseif ($signal === SIGCHLD) {
                $this->cleanupChild($signalInfo);
            }

            // Restart children
            foreach ($this->processRestart as $name => $timeout) {
                // Respect refork timeout
                if (\microtime(true) < $timeout) { continue; }

                // If forkChild() returns false, we are inside the child here
                if (!$this->forkChild($name, $this->processCallbacks[$name])) {
                    return false;
                }
            }

            // All processes seemed to have finished gracefully
            if ((\count($this->processPIDs) === 0) && (\count($this->processCallbacks) === \count($this->processFinished))) {
                break;
            }
        }

        if ($returnWhenFinished) {
            return true;
        } else {
            exit(0);
        }
    }

    /**
     * Fork a process, execute the callback and store process information
     *
     * @param string $name
     * @param callable $callback
     * @return bool True in parent process and False in child process if no callback was supplied
     */
    protected function forkChild($name, callable $callback = null)
    {
        $pid = \pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork process "' . $name . '"".');
        }

        // Forked, do some cleanup
        elseif ($pid === 0) {
            \cli_set_process_title('php ' . $_SERVER['argv'][0] . ' (' . $name . ': ' . ((isset($this->processStartCounter[$name]) ? $this->processStartCounter[$name] : 0) + 1) . ')');
            \pcntl_sigprocmask(SIG_SETMASK, []);

            unset($this->processCallbacks);
            unset($this->processFinished);
            unset($this->processPIDs);
            unset($this->processRestart);
            unset($this->processStartCounter);
            unset($this->processStarted);
            unset($this->processTimeouts);

            if ($callback !== null) {
                call_user_func($callback);
                exit(0);
            } else {
                return false;
            }
        }

        $this->processPIDs[$pid] = $name;
        $this->processStarted[$name] = microtime(true);
        $this->processStartCounter[$name] = (isset($this->processStartCounter[$name]) ? $this->processStartCounter[$name] : 0) + 1;
        unset($this->processRestart[$name]);
        return true;
    }

    /**
     * Do child cleanup if child exited and mark for restart on error
     *
     * @param array $signalInfo Returned from pcntl_sigtimedwait()
     */
    protected function cleanupChild(array $signalInfo, $forceFinished = false)
    {
        if ($signalInfo['signo'] !== SIGCHLD) {
            throw new \InvalidArgumentException('Can only cleanup children on SIGCHLD event.');
        }

        if (!isset($this->processPIDs[$signalInfo['pid']])) {
            throw new \InvalidArgumentException('Received SIGCHLD signal from unknown process "' . $signalInfo['pid'] . '"');
        }

        $name = $this->processPIDs[$signalInfo['pid']];

        // Cleanup child process
        $childStatus = null;
        if (($childPid = \pcntl_waitpid($signalInfo['pid'], $childStatus)) === -1) {
            $errno = \pcntl_get_last_error();
            $error = \pcntl_strerror($errno);
            throw new \RuntimeException('Failed to collect child "' . $signalInfo['pid'] . '": (' . $errno . ') ' . $error);
        }

        unset($this->processPIDs[$signalInfo['pid']]);
        $this->processStarted[$name] = 0;

        // Abort on normal exit (status: 0)
        if ($forceFinished || (\pcntl_wifexited($childStatus) && (\pcntl_wexitstatus($childStatus) === 0))) {
            $this->processFinished[$name] = \microtime(true);
            unset($this->processRestart[$name]);
        }

        else {
            $this->processRestart[$name] = \microtime(true) + (isset($this->processTimeouts[$name]) ? $this->processTimeouts[$name] : 0);
        }
    }

    /**
     * Kill all children with the supplied signal and wait for them to exit
     *
     * @param int $signal
     * @return bool
     */
    protected function killChildren($signal = SIGKILL)
    {
        // Signal all processes to exit
        foreach ($this->processPIDs as $pid => $name) {
            \posix_kill($pid, $signal);
        }

        for($i=0; $i<3000; $i++) {
            if (\count($this->processPIDs) === 0) {
                return true;
            }

            $signalInfo = null;
            if (-1 !== \pcntl_sigtimedwait([SIGCHLD], $signalInfo, 0, 100000000)) {
                $this->cleanupChild($signalInfo, true);
            }
        }

        // Forcefully destroy all remaining processes
        foreach ($this->processPIDs as $pid => $name) {
            \posix_kill($pid, SIGKILL);
        }

        return false;
    }

    /**
     * Kill all children if we are inside the parent process
     */
    public function __destruct()
    {
        if (isset($this->processPIDs) && is_array($this->processPIDs)) {
            $this->killChildren();
        }
    }

    /**
     * Start the watchdog
     * The watchdog will fork and the child's control flow will continue after the WatchDog::run invocation.
     * If the child dies with an error return value, it is restarted.
     * Normal termination (exit(0);) will also terminate the watchdog or make him return True if $returnWhenFinished was enabled.
     *
     * @param bool $returnWhenFinished Whether WatchDog should return or exit(0) when child finished gracefully
     * @return bool False in child processes, True if $returnWhenFinished was set to True in parent process
     */
    public static function run($returnWhenFinished = false)
    {
        $watchdog = new self();
        $watchdog->processCallbacks['default'] = null;
        return $watchdog->start($returnWhenFinished);
    }
}
