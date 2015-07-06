<?php
/*
 * (c) 2015 by nexxes Informationstechnik GmbH
 * All rights reserved.
 * For the license to use this software, see the provided LICENSE file.
 */

namespace nexxes;

/**
 * Helper class to daemonize the current PHP script
 * You should daemonize very early in the process and you MUST not open any resource (like additional file handles, database connections, etc) before daemonizing.
 *
 * @author Dennis Birkholz <dennis.birkholz@nexxes.net>
 */
class Daemon
{
    /**
     * Set this environmental variable to indicate logs have been reopened
     */
    const LOGS_REOPENED_INDICATOR = 'PHP_DAEMONIZE_LOGS_REOPENED';


    /**
     * Daemonize the current process so it can run in the background.
     *
     * If $pidfile is supplied, the process ID is written there.
     * Provide absolute path names for the parameters to avoid file not found errors or logs inside the source code folder.
     *
     * If an error occurred, a RuntimeException is thrown.
     *
     * @param string $pidfile File to write the process ID of the daemon to
     * @param string $stderr File to redirect STDERR to
     * @param string $stdout File to redirect STDOUT to
     * @param string $stdin File to read STDIN from
     * @throws \RuntimeException
     */
    public static function daemonize($pidfile = null, $stderr = '/dev/null', $stdout = '/dev/null', $stdin = '/dev/null')
    {
        // Allow only cli scripts to daemonize, otherwise you may confuse your webserver
        if (\php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Can only daemonize a CLI process!');
        }

        self::checkPID($pidfile);

        self::reopenFDs($stdin, $stdout, $stderr);

        if (($pid1 = @\pcntl_fork()) < 0) {
            throw new \RuntimeException('Failed to fork, reason: "' . \pcntl_strerror(\pcntl_get_last_error()) . '"');
        } elseif ($pid1 > 0) {
            exit;
        }

        if (@posix_setsid() === -1) {
            throw new \RuntimeException('Failed to become session leader, reason: "' . \posix_strerror(\posix_get_last_error()) . '"');
        }

        if (($pid2 = @\pcntl_fork()) < 0) {
            throw new \RuntimeException('Failed to fork, reason: "' . \pcntl_strerror(\pcntl_get_last_error()) . '"');
        } elseif ($pid2 > 0) {
            exit;
        }

        chdir('/');
        umask(0022);

        self::writePID($pidfile);

        return true;
    }

    /**
     * Close and reopen STDIN/STDOUT/STDERR and re-execute script to fix constants.
     */
    private static function reopenFDs($newSTDIN, $newSTDOUT, $newSTDERR)
    {
        // Check reopening was successful
        // STDXX-constants behave a little bit odd
        // If e.g. STDIN is closed, STDIN contains a valid resource of type stream but using it will cause the program to fail.
        // To check STDIN is actually valid, we open a new handle to php://stdin: if that fails, STDIN is closed.
        // Otherwise we can perform our checks on STDIN.
        if (isset($_SERVER[self::LOGS_REOPENED_INDICATOR])) {
            if (false === @fopen('php://stdin', 'r')) {
                throw new \RuntimeException('STDIN is closed.');
            }

            if (!\is_resource(STDIN) || \posix_isatty(STDIN)) {
                throw new \RuntimeException('Could not reopen STDIN.');
            }

            if (\get_resource_type(STDIN) !== 'stream') {
                throw new \RuntimeException('STDIN got replaced by remaining resource of type "' . \get_resource_type(STDIN) . '", avoid opening resources prior to daemonizing.');
            }

            if (false === @fopen('php://stdout', 'a')) {
                throw new \RuntimeException('STDOUT is closed.');
            }

            if (!\is_resource(STDOUT) || \posix_isatty(STDOUT)) {
                throw new \RuntimeException('Could not reopen STDOUT.');
            }

            if (\get_resource_type(STDOUT) !== 'stream') {
                throw new \RuntimeException('STDOUT got replaced by remaining resource of type "' . \get_resource_type(STDOUT) . '", avoid opening resources prior to daemonizing.');
            }

            if (false === @fopen('php://stderr', 'a')) {
                throw new \RuntimeException('STDERR is closed.');
            }

            if (!\is_resource(STDERR) || \posix_isatty(STDERR)) {
                throw new \RuntimeException('Could not reopen STDERR.');
            }

            if (\get_resource_type(STDERR) !== 'stream') {
                throw new \RuntimeException('STDERR got replaced by remaining resource of type "' . \get_resource_type(STDERR) . '", avoid opening resources prior to daemonizing.');
            }

            putenv(self::LOGS_REOPENED_INDICATOR);

            return;
        }

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        // We need to assign the new file handles otherwise they are automatically closed before pcntl_exec()

        if (false === ($stdin = @fopen($newSTDIN, 'r'))) {
            $error = error_get_last();
            throw new \RuntimeException('Failed to reopen STDIN, reason: "' . $error['message'] . '"', $error['type']);
        }

        if (false === ($stdout = @fopen($newSTDOUT, 'a'))) {
            $error = error_get_last();
            throw new \RuntimeException('Failed to reopen STDOUT, reason: "' . $error['message'] . '"', $error['type']);
        }

        if (false === ($stderr = @fopen($newSTDERR, 'a'))) {
            $error = error_get_last();
            throw new \RuntimeException('Failed to reopen STDERR, reason: "' . $error['message'] . '"', $error['type']);
        }

        putenv(self::LOGS_REOPENED_INDICATOR . '=1');

        \pcntl_exec(PHP_BINARY, $_SERVER['argv']);
        exit;
    }

    private static function checkPID($pidfile)
    {
        if ($pidfile === null) {
            return;
        }

        if (!\file_exists($pidfile)) {
            return;
        }

        $pid = (int)\file_get_contents($pidfile);

        // Process exists and is from the same user, so the daemon seems to be running
        if (\posix_kill($pid, 0)) {
            throw new \RuntimeException('Daemon is already running with process id ' . $pid);
        }

        if (!\unlink($pidfile)) {
            throw new \RuntimeException('Failed to remove old pidfile!');
        }
    }

    /**
     * Write the process ID to the pidfile and register a shutdown function for cleanup
     *
     * @param string
     */
    private static function writePID($pidfile)
    {
        if ($pidfile === null) {
            return;
        }

        // Try to create PID dir
        if (!\dirname($pidfile) && !\mkdir(\dirname($pidfile), null, true)) {
            throw new \RuntimeException('Can not create PID file folder "' . \dirname($pidfile) . '"');
        }

        $pid = \posix_getpid();

        if (false === ($fp = @fopen($pidfile, 'x'))) {
            throw new \RuntimeException('Failed to create PID file "' . $pidfile . '".');
        }

        fwrite($fp, $pid);
        fclose($fp);

        register_shutdown_function(function() use ($pidfile, $pid) {
            if (\posix_getpid() !== $pid) { return; }
            unlink($pidfile);
        });
    }
}
