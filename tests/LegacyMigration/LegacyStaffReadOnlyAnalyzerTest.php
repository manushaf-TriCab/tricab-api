<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\LegacyMigration\Persistence\ReadOnlyAuditedSqlConnection;
use TriCab\Api\Product\Migration\RecordingLegacyReadOnlyConnection;
use TriCab\Core\LegacyMigration\Contract\ExistingEntraLinkLookup;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Live\LegacyStaffReadOnlyAnalyzer;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

final class LegacyStaffReadOnlyAnalyzerTest extends TestCase
{
    public function test_analyzes_staff_only_without_writes_or_secrets(): void
    {
        $logger = new InMemoryLegacySqlStatementLogger();
        $audit = new ReadOnlySqlAudit();
        $healthResponses = [
            'SELECT DATABASE()' => [['db_name' => 'tricab']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'ro@%']],
            'SHOW GRANTS' => [['Grants for ro@%' => 'GRANT SELECT ON `tricab`.* TO `ro`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']],
        ];
        $wp = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, $healthResponses + [
            'wp_user_roles' => [['option_value' => serialize(['administrator' => [], 'staff' => [], 'customer' => []])]],
            'FROM `wp_users`' => [
                [
                    'ID' => 1,
                    'user_login' => 'thilan',
                    'user_email' => 'thilan@tricab.com',
                    'display_name' => 'Thilan',
                    'user_status' => 0,
                ],
                [
                    'ID' => 2,
                    'user_login' => 'dup-a',
                    'user_email' => 'dup@tricab.com',
                    'display_name' => 'Dup A',
                    'user_status' => 0,
                ],
                [
                    'ID' => 3,
                    'user_login' => 'dup-b',
                    'user_email' => 'dup@tricab.com',
                    'display_name' => 'Dup B',
                    'user_status' => 0,
                ],
                [
                    'ID' => 4,
                    'user_login' => 'noemail',
                    'user_email' => '',
                    'display_name' => 'No Email',
                    'user_status' => 0,
                ],
                [
                    'ID' => 5,
                    'user_login' => 'badmail',
                    'user_email' => 'not-an-email',
                    'display_name' => 'Bad Mail',
                    'user_status' => 0,
                ],
                [
                    'ID' => 6,
                    'user_login' => 'buyer@example.com',
                    'user_email' => 'buyer@example.com',
                    'display_name' => 'Customer',
                    'user_status' => 0,
                ],
                [
                    'ID' => 7,
                    'user_login' => 'exists',
                    'user_email' => 'existing@tricab.com',
                    'display_name' => 'Existing',
                    'user_status' => 0,
                ],
            ],
            'user_id, meta_key, meta_value' => [
                ['user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['administrator' => true])],
                ['user_id' => 2, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['staff' => true])],
                ['user_id' => 3, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['staff' => true])],
                ['user_id' => 4, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['staff' => true])],
                ['user_id' => 5, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['staff' => true])],
                ['user_id' => 6, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['customer' => true])],
                ['user_id' => 7, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['sales' => true])],
            ],
        ], 'wordpress');
        $tc = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [
            'SELECT DATABASE()' => [['db_name' => 'tc']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'ro@%']],
            'SHOW GRANTS' => [['Grants for ro@%' => 'GRANT SELECT ON `tc`.* TO `ro`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']],
            'FROM `employee`' => [],
        ], 'tc');

        $existingEmails = new class implements ExistingNormalizedEmailLookup {
            public function existingNormalizedEmails(array $normalizedEmails): array
            {
                return in_array('existing@tricab.com', $normalizedEmails, true) ? ['existing@tricab.com'] : [];
            }
        };
        $entraLinks = new class implements ExistingEntraLinkLookup {
            public function linkedNormalizedEmails(array $normalizedEmails): array
            {
                return in_array('thilan@tricab.com', $normalizedEmails, true) ? ['thilan@tricab.com'] : [];
            }
        };

        $analyzer = new LegacyStaffReadOnlyAnalyzer(
            $wp,
            $tc,
            $this->config(),
            new LegacyIdentitySourceHealth(),
            $existingEmails,
            $entraLinks,
            $audit,
            $logger,
        );

        $result = $analyzer->analyze();
        $summary = $result['summary'];

        self::assertSame('STRICT_READ_ONLY', $result['mode']);
        self::assertSame('MEMORY', $result['persistence']);
        self::assertSame(6, $summary['totalStaffCandidates']);
        self::assertSame(4, $summary['staffWithValidEmail']);
        self::assertSame(1, $summary['staffMissingEmail']);
        self::assertSame(1, $summary['staffInvalidEmail']);
        self::assertSame(2, $summary['duplicateEmailStaff']);
        self::assertSame(1, $summary['existingTriCabUserMatches']);
        self::assertSame(1, $summary['eligibleForFutureMigration']);
        self::assertSame(1, $summary['entraAlreadyLinked']);
        self::assertSame(3, $summary['entraLinkRequired']);
        self::assertSame(0, $result['databaseSafety']['legacyWriteAttemptsBlocked']);
        self::assertSame(0, $result['databaseSafety']['azureWriteAttemptsBlocked']);
        self::assertSame(0, $result['databaseSafety']['identityUsersModified']);
        self::assertSame(0, $result['databaseSafety']['migrationStoreModified']);

        foreach ($logger->entries() as $entry) {
            self::assertStringNotContainsString('user_pass', strtolower($entry->sql));
            self::assertStringNotContainsString('user_activation_key', strtolower($entry->sql));
            self::assertStringNotContainsString('session_tokens', strtolower($entry->sql));
        }

        $payload = json_encode($result);
        self::assertIsString($payload);
        self::assertStringNotContainsString('user_pass', $payload);
    }

    public function test_sql_audit_blocks_legacy_writes(): void
    {
        $audit = new ReadOnlySqlAudit();
        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $audit->recordLegacy('UPDATE wp_users SET user_email = "x"');
    }

    public function test_audited_azure_connection_blocks_execute(): void
    {
        $audit = new ReadOnlySqlAudit();
        $inner = new class implements \TriCab\Api\Infrastructure\Database\SqlConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                unset($sql, $params);

                return [];
            }

            public function execute(string $sql, array $params = []): void
            {
                unset($sql, $params);
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollBack(): void
            {
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };
        $audited = new ReadOnlyAuditedSqlConnection($inner, $audit);
        $this->expectException(\RuntimeException::class);
        $audited->execute('INSERT INTO [identity].[Users] VALUES (1)');
    }

    private function config(): LegacyIdentitySourceConfig
    {
        return LegacyIdentitySourceConfig::fromEnvironment([
            'APP_ENV' => 'development',
            'LEGACY_WP_READ_ONLY' => 'true',
            'LEGACY_TC_READ_ONLY' => 'true',
            'LEGACY_WP_DATABASE' => 'tricab',
            'LEGACY_TC_DATABASE' => 'tc',
            'LEGACY_WP_SSL_MODE' => 'required',
            'LEGACY_TC_SSL_MODE' => 'required',
            'LEGACY_WP_DSN' => 'mysql:host=127.0.0.1;dbname=tricab',
            'LEGACY_WP_USER' => 'ro',
            'LEGACY_WP_PASSWORD' => 'secret',
            'LEGACY_TC_DSN' => 'mysql:host=127.0.0.1;dbname=tc',
            'LEGACY_TC_USER' => 'ro',
            'LEGACY_TC_PASSWORD' => 'secret',
            'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER' => 'false',
        ]);
    }
}
