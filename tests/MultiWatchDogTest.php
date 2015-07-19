<?php
/*
 * (c) 2015 by Dennis Birkholz / nexxes Informationstechnik GmbH
 * All rights reserved.
 * For the license to use this software, see the LICENSE file provided with this package.
 */

namespace nexxes;

/**
 * Test multi process mode of WatchDog
 *
 * @author Dennis Birkholz <dennis.birkholz@nexxes.net>
 * @covers \nexxes\WatchDog
 */
class MultiWatchDogTest extends \PHPUnit_Framework_TestCase
{
    protected $tempfiles = [];

    public function tearDown()
    {
        foreach ($this->tempfiles as $tempfile) {
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }
    }

    /**
     * Verify multiple processes are forked and have different process IDs
     *
     * @test
     */
	public function testFork()
	{
        $this->tempfiles[] = $tempfile1 = tempnam(sys_get_temp_dir(), __FUNCTION__ . '__');
        $this->tempfiles[] = $tempfile2 = tempnam(sys_get_temp_dir(), __FUNCTION__ . '__');

        $watchdog = new WatchDog();

        $watchdog->addProcess('process1', function() use ($tempfile1) {
            file_put_contents($tempfile1, time() . ' ' . posix_getpid(), FILE_APPEND);
        });

        $watchdog->addProcess('process2', function() use ($tempfile2) {
            file_put_contents($tempfile2, time() . ' ' . posix_getpid(), FILE_APPEND);
        });

        $watchdog->start(true);

        list(, $pid1) = explode(' ', file_get_contents($tempfile1));
        list(, $pid2) = explode(' ', file_get_contents($tempfile2));

        $this->assertNotEquals(\posix_getpid(), $pid1);
        $this->assertNotEquals(\posix_getpid(), $pid2);
        $this->assertNotEquals($pid1, $pid2);
	}

    /**
     * Verify one process is restarted while the other one continues to run
     *
     * @test
     */
    public function testRestart()
    {
        $this->tempfiles[] = $tempfile = tempnam(sys_get_temp_dir(), __FUNCTION__ . '__');
        $rounds = 10;

        $watchdog = new WatchDog();

        $watchdog->addProcess('process1', function() use ($tempfile, $rounds) {
            file_put_contents($tempfile, time() . ' ' . posix_getpid() . PHP_EOL, FILE_APPEND);

            $lines = \file($tempfile, \FILE_SKIP_EMPTY_LINES|\FILE_IGNORE_NEW_LINES);
            if (count($lines) > $rounds) {
                exit(0);
            }

            sleep(10);
        });

        $watchdog->addProcess('process2', function() use ($tempfile, $rounds) {
            $fp = fopen($tempfile, 'r');
            $taken = 0;

            for ($i=0; $i<$rounds; $i++) {
                do {
                    clearstatcache();
                    usleep(100);
                } while ($taken >= \fstat($fp)['size']);

                $line = fgets($fp);
                $taken += \strlen($line);

                list(, $pid) = explode(' ', trim($line));
                \posix_kill($pid, SIGTERM);
            }
        });

        $watchdog->start(true);
        $pids = [];

        $lines = \file($tempfile, \FILE_SKIP_EMPTY_LINES|\FILE_IGNORE_NEW_LINES);
        foreach ($lines AS $line) {
            list(, $pid) = explode(' ', $line);
            $pids[$pid] = true;
        }

        $this->assertCount($rounds+1, $pids);
    }
}