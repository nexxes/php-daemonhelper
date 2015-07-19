# php-daemonhelper
Helper classes for daemon processes written in PHP, licenced under the LGPL v3.

## Installation

Just put the following in your ```composer.json``` file inside your project root.
No stable version exists so far.

```Json
"require": {
  "nexxes/daemonhelper": "*@dev"
}
```

## Features

### Daemonize a process

```PHP
// Daemonize the current process:
// - process will be detached from terminal and becomes process group leader
// - stdin/stderr will be closed and reopened to the supplied files (default is /dev/null)
// - daemon will re-exec itself so STDIN, STDOUT, STDERR constants are fixed
\nexxes\Daemon::daemonize('run/process.pid', 'log/stderr.log', 'log/stdout.log', 'stdin.txt');

// Code after daemonizing follows here
// ...
```

### WatchDog to restart a worker process on error

```PHP
// Fork the real worker process
\nexxes\WatchDog::run();

// Code for the worker process
// If the process dies with exit-value != 0,
// we start here again and again and again
// ..
```

### WatchDog for multiple worker processes

```PHP
// Multiple processes can be handled by a watchdog instance
$watchdog = new \nexxes\WatchDog();

$watchdog->addProcess('processName1', function() {
  // Code for process 1
  // If the process dies with exit-value != 0,
  // we start here again ...
});

$watchdog->addProcess('processNameX', function() {
  // Register as many processes as you need.
  // You can register anything callable, not only closures.
});

// Execute the processes and restart them if needed:
$watchdog->start();
```
