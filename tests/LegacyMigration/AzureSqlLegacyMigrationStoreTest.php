<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlLegacyMigrationStore;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;

final class AzureSqlLegacyMigrationStoreTest extends TestCase
{
    public function test_writes_only_migration_schema_and_upserts_raw_users(): void
    {
        $db = new FakeSqlConnection();
        $db->queueFetch([['LegacyMigrationBatchId' => 7]]);
        $db->queueFetch([]);
        $store = new AzureSqlLegacyMigrationStore($db);
        $store->upsertRaw('LegacyUsersRaw', [
            'batchPublicId' => '00000000-0000-4000-8000-000000000099',
            'legacyId' => '10',
            'sourceSystem' => 'wordpress',
            'extractedAtUtc' => '2026-08-19T00:00:00Z',
            'originalJson' => ['email' => 'a@b.com'],
            'hash' => 'abc',
            'statusCode' => 'extracted',
        ]);

        $sql = implode("\n", array_map(static fn (array $c): string => $c['sql'], $db->calls));
        self::assertStringContainsString('[migration].[LegacyUsersRaw]', $sql);
        self::assertStringNotContainsString('[identity].[Users]', $sql);
        self::assertStringNotContainsString('[identity].[ExternalIdentities]', $sql);
        self::assertStringNotContainsString('[identity].[UserRoles]', $sql);
        self::assertStringNotContainsString('[customer].[CustomerContactUsers]', $sql);
        self::assertDoesNotMatchRegularExpression('/\b(tricab|tc)\./i', $sql);
    }
}
