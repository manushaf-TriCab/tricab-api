<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Authentication\ProcOpenAzureCliProcessRunner;

final class ProcOpenAzureCliProcessRunnerTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory === null) {
            return;
        }

        $launcher = $this->temporaryDirectory . '/az.cmd';
        if (is_file($launcher)) {
            unlink($launcher);
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    public function test_runs_windows_batch_launcher_from_explicit_path_with_spaces(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows batch launch behavior is Windows-specific.');
        }

        $this->temporaryDirectory = sys_get_temp_dir()
            . '/tricab azure cli runner '
            . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporaryDirectory));

        $launcher = $this->temporaryDirectory . '/az.cmd';
        $bytesWritten = file_put_contents(
            $launcher,
            "@echo off\r\necho {\"azure-cli\":\"test\"}\r\n",
        );
        self::assertIsInt($bytesWritten);

        $runner = new ProcOpenAzureCliProcessRunner();
        [$exitCode, $stdout, $stderr] = $runner->run([
            $launcher,
            'version',
            '--output',
            'json',
        ]);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('{"azure-cli":"test"}', trim($stdout));
        self::assertSame('', trim($stderr));
    }
}
