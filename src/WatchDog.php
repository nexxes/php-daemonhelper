<?php
/*
 * (c) 2015 by nexxes Informationstechnik GmbH
 * All rights reserved.
 * For the license to use this software, see the provided LICENSE file.
 */

namespace nexxes;

/**
 * Do the work inside a child process and restart this process on failure
 *
 * @author Dennis Birkholz <dennis.birkholz@nexxes.net>
 */
abstract class WatchDog
{
    /**
     * Start the watchdog
     * The watchdog will fork and the child's controll flow will continue after the WatchDog::run invocation.
     * If the child dies with an error return value, it is restarted.
     * Normal termination (exit(0);) will also terminate the watchdog.
     */
    public static function run()
    {
        $started = 0;
        $try = 0;
        $signalInfo = null;
        $childStatus = null;

        do {
            $pid = \pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException('Failed to work worker, sorry.');
            }

            // Forked, do some cleanup
            elseif ($pid === 0) {
                \cli_set_process_title('php ' . $_SERVER['argv'][0]);
                \pcntl_sigprocmask(SIG_SETMASK, []);
                return;
            }

            $started = time();
            $try++;

            \cli_set_process_title('Watchdog: instance ' . $try . ' running since ' . date('Y-m-d H:i:s', $started));
            \pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD, SIGTERM]);

            while ($started > 0) {
                if (-1 === \pcntl_sigwaitinfo([SIGCHLD, SIGTERM], $signalInfo)) {
                    continue;
                }
                
                if ($signalInfo['signo'] == SIGTERM) {
                    \posix_kill($pid, SIGTERM);
                    
                    // Check if child died
                    for($i=0; $i<3000; $i++) {
                        echo "Waiting for child: $i\n";
                        
                        if (-1 !== \pcntl_sigtimedwait([SIGCHLD], $signalInfo, 0, 100000000)) {
                            exit;
                        }
                    }
                    
                    \posix_kill($pid, SIGKILL);
                    exit;
                }
                

                // Ignore error for now
                if (($childPid = \pcntl_waitpid($pid, $childStatus)) === -1) {
                    continue;
                }

                $started = 0;

                // Abort on normal exit (status: 0)
                if (\pcntl_wifexited($childStatus) && (\pcntl_wexitstatus($childStatus) === 0)) {
                    exit(0);
                }
            }
        } while (true);
    }
}
