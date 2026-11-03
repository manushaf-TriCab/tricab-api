<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;

/**
 * `bin/run-rfq-mail-reader.php` as the WebJob runs it (a real process, environment stated in full so a
 * developer's `.env` cannot change the answer), the release layout, and the settings' defaults.
 */
final class RfqMailReaderScriptTest extends TestCase
{
    private string $state;

    protected function setUp(): void
    {
        $this->state = sys_get_temp_dir() . '/rfq-mail-script-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->state . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->state) && rmdir($this->state);
    }

    /**
     * @param array<string, string> $over
     * @return array{int, string, string}
     */
    private function runScript(array $over, string ...$args): array
    {
        $env = array_filter(getenv(), 'is_string');
        foreach (array_keys($env) as $key) {
            if (str_starts_with($key, 'RFQ_MAIL_') || str_starts_with($key, 'FOUNDRY_')) {
                unset($env[$key]);
            }
        }
        $env = $over + [
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
            'RFQ_MAIL_STATE_PATH' => $this->state,
        ] + $env;
        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'memory_limit=-1', dirname(__DIR__, 3) . '/bin/run-rfq-mail-reader.php'], array_values($args)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $env,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return array<string, mixed> */
    private static function report(string $out): array
    {
        $report = json_decode($out, true);
        self::assertIsArray($report);

        return array_filter($report, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    public function test_by_default_the_script_does_nothing_and_exits_3(): void
    {
        [$status, $out, $err] = $this->runScript([]);

        self::assertSame(3, $status, $err);
        self::assertSame('', $out . $err);
        self::assertDirectoryDoesNotExist($this->state, 'not even a state directory is made');
    }

    public function test_mode_off_exits_3_even_with_the_read_switch_on(): void
    {
        [$status] = $this->runScript(['RFQ_MAIL_READ_ENABLED' => 'true', 'RFQ_MAIL_MODE' => 'off']);
        self::assertSame(3, $status);
    }

    public function test_the_pause_switch_exits_3_without_configuration(): void
    {
        [$status, , $err] = $this->runScript(['RFQ_MAIL_READ_ENABLED' => 'false', 'RFQ_MAIL_MODE' => 'shadow']);
        self::assertSame(3, $status, $err);
    }

    public function test_switched_on_without_graph_or_foundry_settings_exits_2_and_names_them(): void
    {
        [$status, , $err] = $this->runScript(['RFQ_MAIL_READ_ENABLED' => 'true', 'RFQ_MAIL_MODE' => 'shadow']);

        self::assertSame(2, $status);
        self::assertStringContainsString('RFQ_MAIL_GRAPH_TENANT_ID', $err);
        self::assertStringContainsString('FOUNDRY_ENDPOINT', $err);
    }

    public function test_an_in_memory_mail_store_is_refused_because_recording_would_be_lost(): void
    {
        [$status, , $err] = $this->runScript([
            'RFQ_MAIL_READ_ENABLED' => 'true',
            'RFQ_MAIL_MODE' => 'shadow',
            'RFQ_MAIL_GRAPH_TENANT_ID' => 't',
            'RFQ_MAIL_GRAPH_CLIENT_ID' => 'c',
            'RFQ_MAIL_GRAPH_CLIENT_SECRET' => 's',
            'FOUNDRY_ENABLED' => 'true',
            'FOUNDRY_ENDPOINT' => 'https://tricab-ai2.services.ai.azure.com',
            'FOUNDRY_MODEL_DEPLOYMENT' => 'x',
        ]);

        self::assertSame(2, $status, $err);
        self::assertStringContainsString('in memory', $err);
    }

    public function test_status_and_reset_cursor_work_without_graph_or_a_database(): void
    {
        [$status, $out] = $this->runScript([], '--status');
        self::assertSame(0, $status);
        $report = json_decode($out, true);
        self::assertIsArray($report);
        self::assertFalse($report['hasCursor']);
        self::assertFalse($report['readEnabled']);
        self::assertSame('off', $report['mode']);

        mkdir($this->state, 0775, true);
        file_put_contents($this->state . '/state.json', '{"cursor":"https://graph.microsoft.com/v1.0/x","cursorMailbox":"melbourne@tricab.com","cursorComplete":true}');
        [, $out] = $this->runScript([], '--status');
        self::assertTrue(self::report($out)['hasCursor']);
        [, $out] = $this->runScript(['RFQ_MAIL_MAILBOX' => 'nzprojects@tricab.com'], '--status');
        self::assertFalse(self::report($out)['hasCursor'], 'another mailbox does not inherit the cursor');

        [$status, $out] = $this->runScript([], '--reset-cursor');
        self::assertSame(0, $status);
        self::assertStringContainsString('cursor cleared', $out);
        [, $out] = $this->runScript([], '--status');
        self::assertFalse(self::report($out)['hasCursor']);
    }

    public function test_the_webjob_script_loops_every_minute_and_is_carried_into_the_release_layout(): void
    {
        $root = dirname(__DIR__, 3);
        $run = (string) file_get_contents($root . '/deploy/app-service/webjobs/rfq-mail/run.sh');
        $build = (string) file_get_contents($root . '/scripts/build-app-service-release.sh');
        $buildPs = (string) file_get_contents($root . '/scripts/build-app-service-release.ps1');

        self::assertStringContainsString('php bin/run-rfq-mail-reader.php', $run);
        self::assertStringContainsString('sleep 60', $run);
        self::assertStringContainsString('while true', $run);
        self::assertStringContainsString('75', $run, 'the exit codes are documented in the script');
        self::assertFileExists($root . '/bin/run-rfq-mail-reader.php');
        // Copied into App_Data/jobs/continuous/rfq-mail, next to rfq-reader, and a required release input.
        self::assertStringContainsString('App_Data/jobs/continuous/rfq-mail/run.sh', $build);
        self::assertStringContainsString('"$app_root/bin/run-rfq-mail-reader.php"', $build);
        self::assertSame(2, substr_count($build, '"$api_root/deploy/app-service/webjobs/rfq-mail/run.sh"'));
        self::assertSame(2, substr_count($build, '"$api_root/bin/run-rfq-mail-reader.php"'));
        self::assertStringContainsString('chmod +x "$release_root/App_Data/jobs/continuous/rfq-mail/run.sh"', $build);
        self::assertStringContainsString('jobs\\continuous\\rfq-mail', $buildPs);
        // The prompt the classifier names ships in the release (PROMPTS is copied whole).
        self::assertFileExists($root . '/PROMPTS/rfq-mail-classify-v1.md');
        self::assertStringContainsString('for directory in src resources PROMPTS core', $build);
    }

    public function test_the_settings_are_off_by_default_and_validated(): void
    {
        $base = [
            'APP_ENV' => 'development', 'APP_NAME' => 'tricab-api', 'DEV_AUTH_ENABLED' => 'true', 'USER_REPOSITORY' => 'memory',
            'DB_AUTH_MODE' => 'sql-login', 'GUEST_JWT_ISSUER' => 'https://api.tricab.local', 'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b', 'GUEST_JWT_TTL_SECONDS' => '86400',
            'GUEST_EMAIL_PROVIDER' => 'development', 'PASSKEY_FAKE_CEREMONY' => 'true',
        ];
        $settings = Settings::fromEnvironment($base);
        self::assertFalse($settings->rfqMailReadEnabled);
        self::assertSame('off', $settings->rfqMailMode);
        self::assertFalse($settings->isRfqMailReaderRunning());
        self::assertSame('melbourne@tricab.com', $settings->rfqMailMailbox);
        self::assertSame(2, $settings->rfqMailFirstRunDays);
        self::assertSame('claude-haiku-5-5', $settings->rfqMailClassifierModel);
        self::assertSame('var/rfq-mail', $settings->rfqMailStatePath);

        $on = Settings::fromEnvironment($base + ['RFQ_MAIL_READ_ENABLED' => 'true', 'RFQ_MAIL_MODE' => 'Shadow']);
        self::assertTrue($on->isRfqMailReaderRunning());
        self::assertFalse(Settings::fromEnvironment($base + ['RFQ_MAIL_READ_ENABLED' => 'true'])->isRfqMailReaderRunning(), 'mode off still stops it');

        foreach ([['RFQ_MAIL_MODE' => 'full'], ['RFQ_MAIL_MAILBOX' => 'melbourne']] as $bad) {
            try {
                Settings::fromEnvironment($base + $bad);
                self::fail('expected a refusal for ' . key($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Numbers are clamped to their range, as the platform's other bounded settings are.
        $clamped = Settings::fromEnvironment($base + ['RFQ_MAIL_FIRST_RUN_DAYS' => '0', 'RFQ_MAIL_PASS_SECONDS' => '1', 'RFQ_MAIL_HEARTBEAT_MINUTES' => '99999']);
        self::assertSame([1, 10, 1440], [$clamped->rfqMailFirstRunDays, $clamped->rfqMailPassSeconds, $clamped->rfqMailHeartbeatMinutes]);
    }

    public function test_the_reader_uses_the_rfq_graph_app_unless_a_registration_of_its_own_is_set(): void
    {
        $base = [
            'APP_ENV' => 'development', 'APP_NAME' => 'tricab-api', 'DEV_AUTH_ENABLED' => 'true', 'USER_REPOSITORY' => 'memory',
            'DB_AUTH_MODE' => 'sql-login', 'GUEST_JWT_ISSUER' => 'https://api.tricab.local', 'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b', 'GUEST_JWT_TTL_SECONDS' => '86400',
            'GUEST_EMAIL_PROVIDER' => 'development', 'PASSKEY_FAKE_CEREMONY' => 'true',
            'GRAPH_TENANT_ID' => 'platform-tenant', 'GRAPH_CLIENT_ID' => 'platform-app', 'GRAPH_CLIENT_SECRET' => 'platform-secret',
        ];
        $graph = static fn (Settings $s): array => [$s->rfqMailGraphTenantId, $s->rfqMailGraphClientId, $s->rfqMailGraphClientSecret];

        // None of its own: the platform's GRAPH_* (the RFQ Graph app, 19 Oct 2026).
        $fallback = Settings::fromEnvironment($base);
        self::assertSame(['platform-tenant', 'platform-app', 'platform-secret'], $graph($fallback));
        self::assertTrue($fallback->isMailBodyReadingConfigured());

        // All three of its own win.
        $own = Settings::fromEnvironment($base + ['RFQ_MAIL_GRAPH_TENANT_ID' => 't', 'RFQ_MAIL_GRAPH_CLIENT_ID' => 'c', 'RFQ_MAIL_GRAPH_CLIENT_SECRET' => 's']);
        self::assertSame(['t', 'c', 's'], $graph($own));

        // A partial set is not mixed with the platform's: it stays unconfigured, so the reader says what is missing.
        $partial = Settings::fromEnvironment($base + ['RFQ_MAIL_GRAPH_CLIENT_ID' => 'c']);
        self::assertSame(['', 'c', ''], $graph($partial));
        self::assertFalse($partial->isMailBodyReadingConfigured());

        // Neither set: unconfigured, as before.
        $none = $base;
        unset($none['GRAPH_TENANT_ID'], $none['GRAPH_CLIENT_ID'], $none['GRAPH_CLIENT_SECRET']);
        self::assertFalse(Settings::fromEnvironment($none)->isMailBodyReadingConfigured());
    }
}
