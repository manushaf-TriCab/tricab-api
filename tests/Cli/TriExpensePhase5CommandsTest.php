<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * The Phase 5 command line tools, exercised as the operator runs them.
 *
 * Only the read-only commands are executed here: the digest and purge commands
 * talk to the configured database, so running them from a test could touch a
 * real environment.
 */
final class TriExpensePhase5CommandsTest extends TestCase
{
    private const DOC_INTEL_SECRET = 'phase5-test-doc-intel-secret-value';

    private const OPENAI_SECRET = 'phase5-test-openai-secret-value';

    public function test_everyPhase5CommandIsPresentAndParses(): void
    {
        foreach ([
            'tri-expense-monthly-manager-digest.php',
            'tri-expense-purge-expired-receipts.php',
            'tri-expense-missing-receipt-reminders.php',
            'tri-expense-validate-blob.php',
            'tri-expense-integration-health.php',
            'ensure-expense-schema-phase5.php',
        ] as $command) {
            $path = self::basePath() . '/bin/' . $command;
            self::assertFileExists($path);

            $lint = self::runCommand([PHP_BINARY, '-l', $path]);
            self::assertSame(0, $lint['exitCode'], $command . ': ' . $lint['stdout'] . $lint['stderr']);
        }
    }

    public function test_integrationHealthReportsWiringWithoutRevealingCredentials(): void
    {
        $result = self::runCommand(
            [PHP_BINARY, self::basePath() . '/bin/tri-expense-integration-health.php', '--json'],
            [
                'APP_ENV' => 'development',
                'TRIEXPENSE_OCR_ENABLED' => 'true',
                'TRIEXPENSE_DOC_INTEL_ENDPOINT' => 'https://doc-intel.example',
                'TRIEXPENSE_DOC_INTEL_KEY' => self::DOC_INTEL_SECRET,
                'TRIEXPENSE_OPENAI_ENDPOINT' => 'https://openai.example',
                'TRIEXPENSE_OPENAI_KEY' => self::OPENAI_SECRET,
            ],
        );

        self::assertSame(0, $result['exitCode'], $result['stderr']);

        /** @var array<string, array<string, mixed>> $report */
        $report = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CONFIGURED', $report['ocr']['docIntelKey']);
        self::assertSame('CONFIGURED', $report['ocr']['openAiKey']);
        self::assertSame('YES', $report['ocr']['ocrEffective']);

        $everything = $result['stdout'] . $result['stderr'];
        self::assertStringNotContainsString(self::DOC_INTEL_SECRET, $everything);
        self::assertStringNotContainsString(self::OPENAI_SECRET, $everything);
        // Not even a prefix: a fragment of a key is still material in a ticket.
        self::assertStringNotContainsString(substr(self::DOC_INTEL_SECRET, 0, 8), $everything);
    }

    public function test_integrationHealthStatesItSentNothing(): void
    {
        $result = self::runCommand(
            [PHP_BINARY, self::basePath() . '/bin/tri-expense-integration-health.php'],
            ['APP_ENV' => 'development'],
        );

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('SECRETS_PRINTED=0', $result['stdout']);
        self::assertStringContainsString('MAIL_SENT=0', $result['stdout']);
        self::assertStringContainsString('OCR_CALLS=0', $result['stdout']);
        self::assertStringContainsString('INTEGRATION_HEALTH_OK', $result['stdout']);
    }

    public function test_missingReceiptRemindersStayManual(): void
    {
        $result = self::runCommand(
            [PHP_BINARY, self::basePath() . '/bin/tri-expense-missing-receipt-reminders.php'],
            ['APP_ENV' => 'development'],
        );

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('MANUAL_ACTION_NOT_RUN', $result['stdout']);
        self::assertStringContainsString('monthly manager digest is automatic', $result['stdout']);
    }

    public function test_forcedMissingReceiptRemindersRequireAnOperatorIdentity(): void
    {
        $result = self::runCommand(
            [PHP_BINARY, self::basePath() . '/bin/tri-expense-missing-receipt-reminders.php', '--force'],
            ['APP_ENV' => 'development'],
        );

        self::assertSame(4, $result['exitCode']);
        self::assertStringContainsString('--as-user-id', $result['stderr']);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exitCode:int, stdout:string, stderr:string}
     */
    private static function runCommand(array $command, array $env = []): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        /** @var array<string, string> $inherited */
        $inherited = array_filter(getenv(), 'is_string');

        $process = proc_open($command, $descriptors, $pipes, self::basePath(), array_merge($inherited, $env));
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
