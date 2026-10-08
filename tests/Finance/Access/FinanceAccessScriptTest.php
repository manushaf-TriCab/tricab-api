<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Access;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Access\InMemoryFinancePortalUserMap;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMap;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMatcher;

/**
 * `create-finance-access-schema.sql` and `bin/map-finance-portal-users.php` are written,
 * not run against a shared database. These checks read the script and run the CLI where it
 * stops before any database: no test touches a real portal export or a shared server.
 */
final class FinanceAccessScriptTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_the_schema_script_is_additive_guarded_free_of_outside_keys_and_in_the_local_rebuild(): void
    {
        $dir = self::root() . '/database/scripts/development/';
        $sql = file_get_contents($dir . 'create-finance-access-schema.sql');
        $rebuild = file_get_contents($dir . 'rebuild-local-db.ps1');
        self::assertIsString($sql);
        self::assertIsString($rebuild);
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z_]+)\]/', $code, $created);
        self::assertSame(['access_scopes', 'portal_user_map'], $created[1]);
        self::assertStringContainsString("IF OBJECT_ID(N'[finance].[access_scopes]', N'U') IS NULL", $code);
        self::assertStringContainsString("IF OBJECT_ID(N'[finance].[portal_user_map]', N'U') IS NULL", $code);
        self::assertDoesNotMatchRegularExpression('/\b(DROP|DELETE|TRUNCATE|ALTER|UPDATE)\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/REFERENCES\s+\[(?!finance\])/i', $code);
        self::assertStringContainsString("N'branch', N'mailbox'", $code);
        self::assertGreaterThan(
            (int) strpos($rebuild, "'create-finance-schema.sql'"),
            (int) strpos($rebuild, "'create-finance-access-schema.sql'"),
        );
    }

    public function test_the_app_keys_the_scope_table_allows_are_the_modules(): void
    {
        $sql = file_get_contents(self::root() . '/database/scripts/development/create-finance-access-schema.sql');
        self::assertIsString($sql);
        preg_match("/CK_finance_access_scopes_application_key\\]\\s+CHECK \\(\\[application_key\\] IN \\(([^)]*)\\)\\)/", $sql, $match);
        preg_match_all("/N'([A-Z]+)'/", $match[1] ?? '', $keys);

        self::assertSame(array_keys(Module::APPS), $keys[1]);
    }

    public function test_the_container_serves_empty_in_memory_twins_without_a_database(): void
    {
        $container = TestAppFactory::create([])->getContainer();

        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $container->get(FinanceAccessScopeReader::class));
        self::assertInstanceOf(InMemoryFinancePortalUserMap::class, $container->get(FinancePortalUserMap::class));
        self::assertInstanceOf(FinancePortalUserMatcher::class, $container->get(FinancePortalUserMatcher::class));
        self::assertTrue($container->get(FinanceAccessScopeReader::class)->forUser(1, 'AP')->isUnrestricted());
    }

    /**
     * @param array<string, string> $over
     * @return array{int, string, string}
     */
    private function runScript(array $over, string ...$args): array
    {
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
        ] + array_intersect_key(array_filter(getenv(), 'is_string'), array_flip(['PATH', 'Path', 'SystemRoot', 'TEMP', 'TMP', 'windir', 'HOME'])) + [];
        foreach ($over as $key => $value) {
            $env[$key] = $value;
        }

        $process = proc_open(
            array_merge([PHP_BINARY, self::root() . '/bin/map-finance-portal-users.php'], array_values($args)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root(),
            $env,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function test_the_script_needs_an_export_and_says_how_to_run_it(): void
    {
        [$status, $out, $err] = $this->runScript([]);
        self::assertSame(2, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('--export=', $err);

        [$status] = $this->runScript([], '--export=' . self::root() . '/does-not-exist.json');
        self::assertSame(2, $status);
    }

    public function test_the_script_stops_before_any_database_when_the_platform_has_none(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'portal');
        self::assertIsString($file);
        file_put_contents($file, json_encode([['id' => str_repeat('a', 32), 'email' => 'jane@tricab.com']], JSON_THROW_ON_ERROR));
        try {
            [$status, $out, $err] = $this->runScript(['USER_REPOSITORY' => 'memory'], '--export=' . $file, '--apply');
        } finally {
            unlink($file);
        }

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('SQL user repository', $err);
    }

    public function test_an_export_with_nothing_in_it_is_refused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'portal');
        self::assertIsString($file);
        file_put_contents($file, '[]');
        try {
            [$status, , $err] = $this->runScript([], '--export=' . $file);
        } finally {
            unlink($file);
        }

        self::assertSame(2, $status);
        self::assertStringContainsString('no users', $err);
    }
}
