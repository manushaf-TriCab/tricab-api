<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Reader;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Rfq\Reader\FileRfqReaderWake;

/**
 * When the reader WebJob's pass may go to the database: after a wake-up, while
 * reads are outstanding, after a pass that died, and on the idle check; never
 * otherwise, so the serverless database can pause.
 */
final class FileRfqReaderWakeTest extends TestCase
{
    private string $directory;
    private FileRfqReaderWake $wake;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rfq-reader-wake-' . bin2hex(random_bytes(6));
        $this->wake = new FileRfqReaderWake($this->directory . '/nested');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/nested/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory . '/nested');
        @rmdir($this->directory);
    }

    /** A pass that reached the database and found nothing, so the worker is idle. */
    private function idle(): void
    {
        self::assertTrue($this->wake->beginPass(60));
        $this->wake->endPass(0);
    }

    public function test_the_first_pass_checks_because_none_has_yet(): void
    {
        self::assertTrue($this->wake->beginPass(60), 'a fresh deploy empties the directory');
    }

    public function test_an_idle_pass_leaves_the_database_alone(): void
    {
        $this->idle();

        self::assertFalse($this->wake->beginPass(60));
        self::assertFalse($this->wake->beginPass(60));
    }

    public function test_a_signal_wakes_one_pass(): void
    {
        $this->idle();

        $this->wake->signal();

        self::assertTrue($this->wake->beginPass(60));
        $this->wake->endPass(0);
        self::assertFalse($this->wake->beginPass(60), 'the wake-up is consumed');
    }

    public function test_outstanding_reads_keep_every_pass_checking(): void
    {
        $this->idle();
        $this->wake->signal();

        self::assertTrue($this->wake->beginPass(60));
        $this->wake->endPass(2);
        self::assertTrue($this->wake->beginPass(60));
        $this->wake->endPass(1);
        self::assertTrue($this->wake->beginPass(60));
        $this->wake->endPass(0);
        self::assertFalse($this->wake->beginPass(60));
    }

    public function test_a_pass_that_dies_is_retried(): void
    {
        $this->idle();
        $this->wake->signal();

        self::assertTrue($this->wake->beginPass(60));
        // The database was still resuming: no endPass.

        self::assertTrue($this->wake->beginPass(60));
    }

    public function test_a_signal_during_a_pass_wakes_the_next_one(): void
    {
        $this->idle();
        $this->wake->signal();
        self::assertTrue($this->wake->beginPass(60));

        $this->wake->signal();
        $this->wake->endPass(0);

        self::assertTrue($this->wake->beginPass(60));
    }

    public function test_the_idle_check_comes_round(): void
    {
        $this->idle();
        touch($this->directory . '/nested/checked', time() - 61 * 60);

        self::assertTrue($this->wake->beginPass(60));
        $this->wake->endPass(0);
        self::assertFalse($this->wake->beginPass(60));
    }
}
