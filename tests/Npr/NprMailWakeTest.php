<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Npr\Mail\FileNprMailWake;

/** The sweep only looks at the database when a wake file, an unfinished pass or the idle check says so. */
final class NprMailWakeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/npr-wake-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_an_idle_pass_does_nothing_until_woken(): void
    {
        $wake = new FileNprMailWake($this->dir);
        self::assertTrue($wake->beginPass(1440), 'the first look after a fresh deploy is the idle check');
        $wake->endPass(0);

        self::assertFalse($wake->beginPass(1440), 'nothing outstanding, no signal: no database');
        self::assertFalse($wake->beginPass(1440));

        $wake->signal();
        self::assertTrue($wake->beginPass(1440), 'a failed send wakes it');
        self::assertFalse(is_file($this->dir . '/wake'), 'the wake-up is consumed');
        self::assertTrue($wake->beginPass(1440), 'still pending until a pass finds nothing outstanding');
        $wake->endPass(2);
        self::assertTrue($wake->beginPass(1440), 'rows outstanding keep it going');
        $wake->endPass(0);
        self::assertFalse($wake->beginPass(1440));
    }

    public function test_the_idle_check_finds_a_lost_signal(): void
    {
        $wake = new FileNprMailWake($this->dir);
        $wake->beginPass(60);
        $wake->endPass(0);
        touch($this->dir . '/checked', time() - 3 * 3600);

        self::assertTrue($wake->beginPass(60));
    }

    /** The real CLI, with no database configured at all: a quiet pass exits 0 without touching it. */
    public function test_the_sweep_command_is_a_no_op_without_a_wake_file(): void
    {
        mkdir($this->dir, 0775, true);
        touch($this->dir . '/checked');
        [$status, $out, $err] = $this->sweep(['NPR_MAIL_MODE' => 'test', 'NPR_MAIL_WAKE_PATH' => $this->dir], ['--if-woken']);

        self::assertSame(0, $status, $err);
        self::assertSame('', trim($out), 'a skipped pass prints nothing');
    }

    public function test_the_sweep_command_runs_when_woken_and_clears_the_wake(): void
    {
        mkdir($this->dir, 0775, true);
        touch($this->dir . '/checked');
        touch($this->dir . '/wake');
        [$status, $out, $err] = $this->sweep(['NPR_MAIL_MODE' => 'test', 'NPR_MAIL_WAKE_PATH' => $this->dir], ['--if-woken']);

        self::assertSame(0, $status, $err);
        $summary = json_decode(trim($out), true);
        self::assertIsArray($summary);
        self::assertSame(0, $summary['outstanding']);
        self::assertFalse(is_file($this->dir . '/wake'));
        self::assertFalse(is_file($this->dir . '/pending'), 'nothing outstanding, so the pending flag is cleared');
    }

    public function test_the_sweep_command_is_paused_when_mail_is_off(): void
    {
        [$status] = $this->sweep(['NPR_MAIL_MODE' => 'off', 'NPR_MAIL_WAKE_PATH' => $this->dir], ['--if-woken']);

        self::assertSame(3, $status);
    }

    public function test_the_sweep_command_refuses_when_switched_on_but_not_configured(): void
    {
        [$status, , $err] = $this->sweep(['NPR_MAIL_MODE' => 'live', 'NPR_MAIL_WAKE_PATH' => $this->dir, 'NPR_MAIL_SENDER' => ''], []);

        self::assertSame(2, $status);
        self::assertStringContainsString('NPR_MAIL_SENDER', $err);
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $args
     * @return array{int, string, string}
     */
    private function sweep(array $env, array $args): array
    {
        $env += [
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'GRAPH_TENANT_ID' => 'tenant',
            'GRAPH_CLIENT_ID' => 'client',
            'GRAPH_CLIENT_SECRET' => 'secret',
            'NPR_MAIL_SENDER' => 'npr@example.test',
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
        ];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/run-npr-mail-sweep.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
