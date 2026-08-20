<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Migration\RecordingLegacyReadOnlyConnection;
use TriCab\Core\LegacyMigration\Application\Exception\InvalidLegacyMigrationState;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Plugin\LegacyWordPressUserExtractor;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

final class LegacyWordPressUserExtractorTest extends TestCase
{
    public function test_read_only_flag_missing_blocks(): void
    {
        $extractor = $this->extractor(['LEGACY_WP_READ_ONLY' => 'false', 'LEGACY_TC_READ_ONLY' => 'true']);
        $this->expectException(InvalidLegacyMigrationState::class);
        iterator_to_array($extractor->extract([]));
    }

    public function test_extracts_safe_columns_and_classifies(): void
    {
        $logger = new InMemoryLegacySqlStatementLogger();
        $wp = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [
            'SELECT DATABASE()' => [['db_name' => 'tricab']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'ro@%']],
            'SHOW GRANTS' => [['Grants for ro@%' => 'GRANT SELECT ON `tricab`.* TO `ro`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']],
            'wp_user_roles' => [['option_value' => serialize(['administrator' => [], 'customer' => [], 'weirdrole' => []])]],
            'LOWER(user_email)' => [['email_key' => 'dup@example.com', 'record_count' => 2]],
            'GROUP BY meta_key' => [
                ['meta_key' => 'first_name', 'record_count' => 1],
                ['meta_key' => 'session_tokens', 'record_count' => 9],
                ['meta_key' => 'unknown_widget', 'record_count' => 3],
            ],
            'FROM `wp_users`' => [[
                'ID' => 10,
                'user_login' => 'thilan',
                'user_nicename' => 'thilan',
                'user_email' => 'thilan@tricab.com',
                'user_url' => '',
                'user_registered' => '2020-01-01 00:00:00',
                'user_status' => 0,
                'display_name' => 'Thilan',
            ]],
            'user_id, meta_key, meta_value' => [[
                'user_id' => 10,
                'meta_key' => 'first_name',
                'meta_value' => 'Thilan',
            ], [
                'user_id' => 10,
                'meta_key' => 'wp_capabilities',
                'meta_value' => serialize(['staff' => true, 'weirdrole' => true]),
            ]],
        ], 'wordpress');
        $tc = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [
            'SELECT DATABASE()' => [['db_name' => 'tc']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'ro@%']],
            'SHOW GRANTS' => [['Grants for ro@%' => 'GRANT SELECT ON `tc`.* TO `ro`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']],
            'FROM `employee`' => [['user_login' => 'thilan']],
            'FROM `contacts`' => [['id' => 5, 'contact_id' => 88, 'email' => 'buyer@example.com']],
            'customer_contact' => [['contact_id' => 10]],
            'FROM `ali_flex_cert`' => [['userid' => 10, 'record_count' => 2]],
        ], 'tc');

        $extractor = new LegacyWordPressUserExtractor(
            $wp,
            $tc,
            $this->config([
                'LEGACY_WP_READ_ONLY' => 'true',
                'LEGACY_TC_READ_ONLY' => 'true',
                'LEGACY_WP_DATABASE' => 'tricab',
                'LEGACY_TC_DATABASE' => 'tc',
                'LEGACY_WP_SSL_MODE' => 'required',
                'LEGACY_TC_SSL_MODE' => 'required',
            ]),
            new LegacyIdentitySourceHealth(),
        );

        $records = iterator_to_array($extractor->extract([]));
        $users = array_values(array_filter($records, static fn ($r) => $r->recordType === 'users' && $r->sourceIdentifier === '10'));
        self::assertCount(1, $users);
        $payload = $users[0]->payload;
        self::assertSame(10, $payload['legacyWordPressUserId']);
        self::assertArrayNotHasKey('user_pass', $payload);
        self::assertArrayNotHasKey('user_activation_key', $payload);
        self::assertTrue($payload['hasAliFlexRecords']);
        self::assertNotNull($payload['employeeMatch']);
        self::assertContains('EXPLICIT_WP_ID', array_column($payload['contactMatches'], 'matchType'));

        $sql = implode("\n", array_map(static fn ($e) => $e->sql, $logger->entries()));
        self::assertStringNotContainsString('user_pass', $sql);
        self::assertStringNotContainsString('user_activation_key', $sql);
        self::assertStringNotContainsString('SELECT *', strtoupper($sql));

        $inventory = array_values(array_filter($records, static fn ($r) => $r->recordType === 'user-meta-inventory'));
        self::assertNotSame([], $inventory);
        $keys = $inventory[0]->payload['keys'];
        $unknown = array_values(array_filter($keys, static fn ($k) => ($k['metaKey'] ?? '') === 'unknown_widget'));
        self::assertSame('unknown', $unknown[0]['classification']);
        $session = array_values(array_filter($keys, static fn ($k) => ($k['metaKey'] ?? '') === 'session_tokens'));
        self::assertSame([], $session);
    }

    public function test_write_grants_and_wrong_database_block(): void
    {
        $logger = new InMemoryLegacySqlStatementLogger();
        $wp = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [
            'SELECT DATABASE()' => [['db_name' => 'mysql']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'root@%']],
            'SHOW GRANTS' => [['GRANT' => 'GRANT ALL PRIVILEGES ON *.* TO `root`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS']],
        ], 'wordpress');
        $tc = new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [
            'SELECT DATABASE()' => [['db_name' => 'tc']],
            'SELECT CURRENT_USER()' => [['session_user_name' => 'ro@%']],
            'SHOW GRANTS' => [['GRANT' => 'GRANT SELECT ON `tc`.* TO `ro`@`%`']],
            "SHOW STATUS LIKE 'Ssl_cipher'" => [['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS']],
        ], 'tc');
        $extractor = new LegacyWordPressUserExtractor(
            $wp,
            $tc,
            $this->config([
                'LEGACY_WP_READ_ONLY' => 'true',
                'LEGACY_TC_READ_ONLY' => 'true',
                'LEGACY_WP_DATABASE' => 'tricab',
                'LEGACY_TC_DATABASE' => 'tc',
            ]),
            new LegacyIdentitySourceHealth(),
        );
        $this->expectException(InvalidLegacyMigrationState::class);
        iterator_to_array($extractor->extract([]));
    }

    /**
     * @param array<string, string|null> $env
     */
    private function extractor(array $env): LegacyWordPressUserExtractor
    {
        $logger = new InMemoryLegacySqlStatementLogger();
        return new LegacyWordPressUserExtractor(
            new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [], 'wp'),
            new RecordingLegacyReadOnlyConnection(new LegacyReadOnlySqlGuard(), $logger, [], 'tc'),
            $this->config($env),
            new LegacyIdentitySourceHealth(),
        );
    }

    /**
     * @param array<string, string|null> $env
     */
    private function config(array $env): LegacyIdentitySourceConfig
    {
        return LegacyIdentitySourceConfig::fromEnvironment($env + [
            'APP_ENV' => 'development',
            'LEGACY_WP_DSN' => 'mysql:host=127.0.0.1;dbname=tricab',
            'LEGACY_WP_USER' => 'ro',
            'LEGACY_WP_PASSWORD' => 'not-a-real-secret',
            'LEGACY_TC_DSN' => 'mysql:host=127.0.0.1;dbname=tc',
            'LEGACY_TC_USER' => 'ro',
            'LEGACY_TC_PASSWORD' => 'not-a-real-secret',
            'LEGACY_WP_SSL_MODE' => 'required',
            'LEGACY_TC_SSL_MODE' => 'required',
        ]);
    }
}
