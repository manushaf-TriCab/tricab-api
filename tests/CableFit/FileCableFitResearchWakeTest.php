<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableFit\Research\FileCableFitResearchWake;
use TriCab\Api\Config\Settings;

/** The worker goes to the database only when a wake, a pending marker or the idle check says so. */
final class FileCableFitResearchWakeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cablefit-wake-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_the_first_pass_ever_is_an_idle_check(): void
    {
        $wake = new FileCableFitResearchWake($this->dir);

        self::assertTrue($wake->beginPass(1440));
        self::assertFileExists($this->dir . '/pending');
    }

    public function test_an_idle_pass_does_not_go_to_the_database(): void
    {
        $wake = new FileCableFitResearchWake($this->dir);
        $wake->beginPass(1440);
        $wake->endPass(0);

        self::assertFileDoesNotExist($this->dir . '/pending');
        self::assertFileExists($this->dir . '/checked');
        self::assertFalse($wake->beginPass(1440));
        self::assertFalse($wake->beginPass(1440));
        self::assertFileDoesNotExist($this->dir . '/pending');
    }

    public function test_a_signal_wakes_one_pass_and_is_consumed(): void
    {
        $wake = new FileCableFitResearchWake($this->dir);
        $wake->beginPass(1440);
        $wake->endPass(0);

        $wake->signal();
        self::assertFileExists($this->dir . '/wake');

        self::assertTrue($wake->beginPass(1440));
        self::assertFileDoesNotExist($this->dir . '/wake');
        self::assertFileExists($this->dir . '/pending');

        $wake->endPass(0);
        self::assertFalse($wake->beginPass(1440));
    }

    public function test_pending_survives_a_pass_that_dies_and_a_pass_that_leaves_work(): void
    {
        $wake = new FileCableFitResearchWake($this->dir);
        $wake->beginPass(1440);
        $wake->endPass(0);
        $wake->signal();

        // A pass that started and never finished (the database was resuming) leaves pending set.
        self::assertTrue($wake->beginPass(1440));
        self::assertTrue($wake->beginPass(1440));

        // A pass that reached the database but left runs queued keeps it set too.
        $wake->endPass(2);
        self::assertFileExists($this->dir . '/pending');
        self::assertTrue($wake->beginPass(1440));

        $wake->endPass(0);
        self::assertFalse($wake->beginPass(1440));
    }

    public function test_the_idle_check_finds_a_lost_signal_after_its_interval(): void
    {
        $wake = new FileCableFitResearchWake($this->dir);
        $wake->beginPass(60);
        $wake->endPass(0);
        self::assertFalse($wake->beginPass(60));

        touch($this->dir . '/checked', time() - 61 * 60);

        self::assertTrue($wake->beginPass(60));
    }

    public function test_the_idle_check_interval_is_at_least_a_minute(): void
    {
        $wake = new FileCableFitResearchWake($this->dir);
        $wake->beginPass(0);
        $wake->endPass(0);
        touch($this->dir . '/checked', time() - 30);

        self::assertFalse($wake->beginPass(0));
    }

    public function test_signalling_creates_the_directory_and_never_throws(): void
    {
        (new FileCableFitResearchWake($this->dir . '/deeper/still'))->signal();
        self::assertFileExists($this->dir . '/deeper/still/wake');
        @unlink($this->dir . '/deeper/still/wake');
        @rmdir($this->dir . '/deeper/still');
        @rmdir($this->dir . '/deeper');

        // An unwritable location is logged, not thrown: the run row is the record.
        $previous = ini_set('error_log', sys_get_temp_dir() . '/cablefit-wake-test.log');
        try {
            (new FileCableFitResearchWake('/proc/definitely/not/writable'))->signal();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_directory_comes_from_the_setting(): void
    {
        $absolute = Settings::fromEnvironment(['APP_ENV' => 'development', 'CABLEFIT_RESEARCH_WAKE_PATH' => '/var/wake-here/']);
        self::assertSame('/var/wake-here', FileCableFitResearchWake::fromSettings($absolute)->directory());

        $relative = FileCableFitResearchWake::fromSettings(Settings::fromEnvironment(['APP_ENV' => 'development']));
        self::assertStringEndsWith('/var/cablefit-research', $relative->directory());
        self::assertStringStartsWith('/', $relative->directory());
        self::assertSame(dirname(__DIR__, 2) . '/var/cablefit-research', $relative->directory());
    }
}
