# php-daemonhelper
Helper classes for daemon processes written in PHP, licenced under the LGPL v3.

## Daemonize a process

```PHP
// Daemonize the current process:
// - process will be detached from terminal and becomes process group leader
// - stdin/stderr will be closed and reopened to the supplied filed (default is /dev/null)
// - daemon will re-exec itself so STDIN, STDOUT, STDERR constants are fixed
\nexxes\Daemon::daemonize('run/process.pid', 'log/stderr.log', 'log/stdout.log', 'stdin.txt');

// Code after daemonizing follows here
// ...
```

## WatchDog to restart a worker process on error

```PHP
// Fork the real worker process
\nexxes\WatchDog::run();

// Code for the worker process
// If process dies with exit-value != 0, we start here again
// ..
```
