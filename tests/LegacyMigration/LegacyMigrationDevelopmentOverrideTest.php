<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Migration\GuardedReadOnlyMysqlConnection;
use TriCab\Api\Product\Migration\RecordingLegacyReadOnlyConnection;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Live\LegacyMigrationDevelopmentOverride;
use TriCab\Core\LegacyMigration\Live\LegacyMigrationExtractionPolicy;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

final class LegacyMigrationDevelopmentOverrideTest extends TestCase
{
    /** @return array<string, mixed> */
    private function connectedHealth(bool $selectOnly): array
    {
        return [
            'code' => 'wordpress',
            'configured' => true,
            'connected' => true,
            'ssl' => true,
            'sslRequired' => true,
            'readOnlyConfigured' => true,
            'readOnlyVerified' => $selectOnly,
            'safeForExtraction' => $selectOnly,
            'database' => 'tricab',
            'expectedDatabase' => 'tricab',
            'errors' => $selectOnly ? [] : ['Legacy credential contains write privileges. Extraction has been blocked. Use a SELECT-only migration account.'],
            'message' => $selectOnly ? 'SELECT-only legacy connection verified.' : 'Legacy credential contains write privileges.',
            'health' => $selectOnly ? 'healthy' : 'blocked',
        ];
    }

    public function test_select_only_account_allows_extraction(): void
    {
        $policy = new LegacyMigrationExtractionPolicy();
        $report = $policy->apply($this->connectedHealth(true), true, LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'false',
        ]));

        self::assertTrue($report['selectOnlyGrantVerified']);
        self::assertTrue($report['safeForExtraction']);
        self::assertTrue($report['extractionAllowed']);
        self::assertFalse($report['developmentOverrideEnabled']);
        self::assertSame(LegacyMigrationDevelopmentOverride::SAFETY_SELECT_ONLY, $report['safetyLevel']);
    }

    public function test_write_capable_account_without_override_is_blocked(): void
    {
        $policy = new LegacyMigrationExtractionPolicy();
        $report = $policy->apply($this->connectedHealth(false), true, LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'false',
        ]));

        self::assertFalse($report['selectOnlyGrantVerified']);
        self::assertFalse($report['safeForExtraction']);
        self::assertFalse($report['extractionAllowed']);
        self::assertFalse($report['developmentOverrideEnabled']);
    }

    public function test_development_override_allows_write_capable_reader(): void
    {
        $override = LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'true',
        ]);
        $policy = new LegacyMigrationExtractionPolicy();
        $report = $policy->apply($this->connectedHealth(false), true, $override);

        self::assertFalse($report['selectOnlyGrantVerified']);
        self::assertFalse($report['safeForExtraction']);
        self::assertTrue($report['extractionAllowed']);
        self::assertTrue($report['developmentOverrideEnabled']);
        self::assertSame(LegacyMigrationDevelopmentOverride::SAFETY_DEVELOPMENT_OVERRIDE, $report['safetyLevel']);
        self::assertStringContainsString('development-only override', (string) $report['message']);
    }

    public function test_production_rejects_override_at_startup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('permitted only in development');
        LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'production',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'true',
        ]);
    }

    public function test_staging_rejects_override_at_startup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'staging',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'true',
        ]);
    }

    public function test_development_override_requires_wp_read_only_flag(): void
    {
        $override = LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'true',
        ]);
        $policy = new LegacyMigrationExtractionPolicy();
        $report = $policy->apply($this->connectedHealth(false), false, $override);

        self::assertFalse($report['extractionAllowed']);
        self::assertFalse($report['developmentOverrideEnabled']);
    }

    public function test_development_override_requires_tc_read_only_flag(): void
    {
        $override = LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'true',
        ]);
        $base = $this->connectedHealth(false);
        $base['readOnlyConfigured'] = false;
        $policy = new LegacyMigrationExtractionPolicy();
        $report = $policy->apply($base, false, $override);

        self::assertFalse($report['extractionAllowed']);
    }

    public function test_guarded_connection_rejects_writes_even_with_override_active(): void
    {
        $guard = new LegacyReadOnlySqlGuard();
        $conn = new GuardedReadOnlyMysqlConnection(
            new \PDO('sqlite::memory:'),
            $guard,
            new InMemoryLegacySqlStatementLogger(),
            'test',
        );
        foreach ([
            'INSERT INTO wp_users (user_login) VALUES (\'x\')',
            'UPDATE wp_users SET user_login = \'x\'',
            'DELETE FROM wp_users',
            'DROP TABLE wp_users',
            'ALTER TABLE wp_users ADD COLUMN x INT',
            'TRUNCATE wp_users',
            'CREATE TABLE tmp (id INT)',
            'REPLACE INTO wp_users (ID) VALUES (1)',
        ] as $sql) {
            try {
                $conn->query($sql, [], 'test', 'write');
                self::fail('Expected write rejection for: ' . $sql);
            } catch (LegacyDatabaseWriteAttemptException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_health_inspect_keeps_select_only_grant_false_under_override(): void
    {
        $conn = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), new InMemoryLegacySqlStatementLogger(), [
            'SELECT DATABASE()' => [['db_name' => 'tricab']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'writer@%']],
            'SHOW GRANTS' => [['GRANT' => 'GRANT ALL PRIVILEGES ON `tricab`.* TO `writer`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']],
        ], 'wordpress');
        $override = LegacyMigrationDevelopmentOverride::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'true',
        ]);
        $report = (new LegacyIdentitySourceHealth())->inspect(
            $conn,
            'wordpress',
            'tricab',
            true,
            'required',
            $override,
        );

        self::assertFalse($report['selectOnlyGrantVerified']);
        self::assertFalse($report['readOnlyVerified']);
        self::assertFalse($report['safeForExtraction']);
        self::assertTrue($report['extractionAllowed']);
        self::assertTrue($report['developmentOverrideEnabled']);
    }
}
