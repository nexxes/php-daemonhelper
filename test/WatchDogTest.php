<?php
/*
 * (c) 2015 by Dennis Birkholz / nexxes Informationstechnik GmbH
 * All rights reserved.
 * For the license to use this software, see the LICENSE file provided with this package.
 */

namespace nexxes;

/**
 * @author Dennis Birkholz <dennis.birkholz@nexxes.net>
 */
class WatchDogTest extends \PHPUnit_Framework_TestCase
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
     * Verify simple watchdog run forks a process and exists if process terminates without error
     *
     * @test
     */
	public function testFork()
	{
        $this->executeWatchdogTest(1, function ($tempfile) {
            file_put_contents($tempfile, time() . ' ' . posix_getpid() , FILE_APPEND);
            exit(0);
        });
	}

    /**
     * Check WatchDog restarts processes terminated with an error return code
     *
     * @test
     */
    public function testRestart()
    {
        $this->executeWatchdogTest(2, function ($tempfile) {
            clearstatcache();

            if (file_exists($tempfile)) {
                file_put_contents($tempfile, PHP_EOL . time() . ' ' . posix_getpid() , FILE_APPEND);
                exit(0);
            }

            else {
                file_put_contents($tempfile, time() . ' ' . posix_getpid(), FILE_APPEND);
                exit(1);
            }
        });
    }



    /**
     *
     */
    public function executeWatchdogTest($expectedLines, callable $childCallback)
    {
        $tempfile = tempnam(sys_get_temp_dir(), __FUNCTION__ . '__');
        unlink($tempfile);
        $this->tempfiles[] = $tempfile;

        // After the watchdog
        if (WatchDog::run(true)) {
            $lines = file($tempfile);

            $this->assertCount($expectedLines, $lines);

            foreach ($lines as list($time, $pid)) {
                $this->assertNotEmpty($pid);
                $this->assertNotEquals(posix_getpid(), $pid);
            }
        }

        // Forked Child
        else {
            $childCallback($tempfile);
        }
    }
}