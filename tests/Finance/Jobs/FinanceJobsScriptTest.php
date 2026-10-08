<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Jobs;

use PHPUnit\Framework\TestCase;

/**
 * `bin/run-finance-jobs.php` as the WebJob runs it: a real PHP process, with the
 * module switch off and on. In memory throughout (`USER_REPOSITORY=memory`), so it
 * needs no database, and the environment is stated in full so a developer's own
 * `.env` cannot change what it proves.
 */
final class FinanceJobsScriptTest extends TestCase
{
    /**
     * @param array<string, string> $over
     * @return array{int, string, string}
     */
    private function runScript(array $over, string ...$args): array
    {
        $env = array_filter(getenv(), 'is_string') + [];
        // A stated environment: the platform's own test defaults, then this test's.
        $env = [
            'APP_ENV' => 'development',
            'APP_NAME' => 'tricab-api',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'memory',
            'DB_AUTH_MODE' => 'sql-login',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'GUEST_JWT_TTL_SECONDS' => '86400',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'PASSKEY_FAKE_CEREMONY' => 'true',
            'APP_CACHE_DRIVER' => 'array',
            'APP_PERFORMANCE_LOGGING' => 'false',
            'FINANCE_MODULE_ENABLED' => 'false',
            // The script fills anything not stated here from the developer's `.env`, so every
            // setting that registers a job or reaches another system is stated: no app, no mail,
            // no Odoo. A single space, because Windows drops a variable set to the empty string.
            'FINANCE_TASK_ENABLED' => 'false',
            // Tri-AR registers jobs that read a mailbox, call a model and send mail (Phase 5): all off here.
            'FINANCE_AR_ENABLED' => 'false',
            'FINANCE_AR_SENDING_ENABLED' => 'false',
            'FINANCE_MAILBOX_READ_ENABLED' => 'false',
            'FINANCE_AI_ENABLED' => 'false',
            'FINANCE_MAIL_ENABLED' => 'false',
            'ODOO_URL' => ' ',
            'ODOO_API_KEY' => ' ',
        ] + array_intersect_key($env, array_flip(['PATH', 'Path', 'SystemRoot', 'TEMP', 'TMP', 'windir', 'HOME'])) + [];
        foreach ($over as $key => $value) {
            $env[$key] = $value;
        }

        $process = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/run-finance-jobs.php'], array_values($args)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $env,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function test_with_the_module_switch_off_it_prints_one_line_and_exits_zero_touching_nothing(): void
    {
        [$status, $out, $err] = $this->runScript(['FINANCE_MODULE_ENABLED' => 'false']);

        self::assertSame(0, $status, $err);
        $line = json_decode(trim($out), true);
        self::assertIsArray($line);
        self::assertFalse($line['enabled']);
        self::assertArrayNotHasKey('jobs', $line);
    }

    public function test_with_the_module_switch_unset_it_is_off_too(): void
    {
        [$status, $out] = $this->runScript(['FINANCE_MODULE_ENABLED' => '']);

        self::assertSame(0, $status);
        $line = json_decode(trim($out), true);
        self::assertIsArray($line);
        self::assertFalse($line['enabled'] ?? null);
    }

    public function test_with_the_module_switch_on_it_runs_a_pass_with_no_jobs_and_exits_zero(): void
    {
        [$status, $out, $err] = $this->runScript(['FINANCE_MODULE_ENABLED' => 'true']);

        self::assertSame(0, $status, $err);
        $line = json_decode(trim($out), true);
        self::assertIsArray($line);
        self::assertTrue($line['enabled']);
        self::assertSame(
            ['jobs' => 0, 'ran' => 0, 'failed' => 0, 'notDue' => 0, 'alreadyClaimed' => 0, 'claimErrors' => 0, 'failedJobs' => []],
            array_intersect_key($line, array_flip(['jobs', 'ran', 'failed', 'notDue', 'alreadyClaimed', 'claimErrors', 'failedJobs'])),
        );
    }

    public function test_the_webjob_and_the_release_script_carry_the_runner(): void
    {
        $root = dirname(__DIR__, 3);
        $run = file_get_contents($root . '/deploy/app-service/webjobs/finance-jobs/run.sh');
        $build = file_get_contents($root . '/scripts/build-app-service-release.sh');
        self::assertIsString($run);
        self::assertIsString($build);

        self::assertStringContainsString('php bin/run-finance-jobs.php', $run);
        self::assertStringContainsString('sleep 60', $run);
        self::assertStringContainsString('webjobs/finance-jobs/run.sh', $build);
        self::assertStringContainsString('bin/run-finance-jobs.php', $build);
        self::assertStringContainsString('App_Data/jobs/continuous/finance-jobs', $build);
        // Both are listed as required inputs, so a release cannot be built without them.
        self::assertSame(2, substr_count($build, '"$api_root/deploy/app-service/webjobs/finance-jobs/run.sh"'));
    }

    public function test_the_jobs_schema_script_is_additive_guarded_and_part_of_the_local_rebuild(): void
    {
        $root = dirname(__DIR__, 3) . '/database/scripts/development/';
        $sql = file_get_contents($root . 'create-finance-jobs-schema.sql');
        $rebuild = file_get_contents($root . 'rebuild-local-db.ps1');
        self::assertIsString($sql);
        self::assertIsString($rebuild);
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        self::assertStringContainsString("IF OBJECT_ID(N'[finance].[job_runs]', N'U') IS NULL", $code);
        self::assertStringContainsString('PRIMARY KEY CLUSTERED ([job_name], [slot])', $code);
        self::assertDoesNotMatchRegularExpression('/\\b(DROP|DELETE|TRUNCATE|ALTER)\\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/REFERENCES\\s+\\[(?!finance\\])/i', $code);
        self::assertStringNotContainsString('AUTHORIZATION', $code);
        self::assertLessThan(
            (int) strpos($rebuild, "'create-finance-jobs-schema.sql'"),
            (int) strpos($rebuild, "'create-finance-schema.sql'"),
            'the jobs script runs after the shared schema script',
        );
    }
}
