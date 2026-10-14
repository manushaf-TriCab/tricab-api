<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\AzureSqlProductPageContentRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * PDO_SQLSRV binds every occurrence of a named parameter separately, so one name may appear only ONCE per statement.
 */
final class AzureSqlProductPageContentRepositoryTest extends TestCase
{
    private const PRODUCT = '11111111-2222-3333-4444-555555555555';

    public function test_save_uses_unique_named_placeholders_that_match_the_bindings(): void
    {
        $connection = new class () implements SqlConnection {
            /** @var list<array{sql: string, params: array<string, mixed>}> */
            public array $log = [];
            public function fetchAll(string $sql, array $params = []): array { $this->log[] = ['sql' => $sql, 'params' => $params]; return str_contains($sql, 'UPDLOCK') ? [] : [['ProductId' => 1]]; }
            public function execute(string $sql, array $params = []): void {}
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function inTransaction(): bool { return false; }
        };
        $repo = new AzureSqlProductPageContentRepository($connection);
        $repo->save(ProductPublicId::fromString(self::PRODUCT), ProductPageContent::fromInput(false, "Other core colours available on request.", true), UserId::fromInt(7), null);
        $repo->find(ProductPublicId::fromString(self::PRODUCT));

        self::assertCount(3, $connection->log); // version check (UPDLOCK), MERGE, find
        foreach ($connection->log as $statement) {
            preg_match_all('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', $statement['sql'], $m);
            $placeholders = $m[0];
            self::assertSame($placeholders, array_values(array_unique($placeholders)), 'a named parameter appears more than once');
            self::assertEqualsCanonicalizing($placeholders, array_keys($statement['params']), 'placeholders and bindings differ');
        }
        $save = $connection->log[1]['params'];
        self::assertSame(0, $save[':showMarking']);
        self::assertSame(0, $save[':showMarkingInsert']);
        self::assertSame(1, $save[':showNote']);
        self::assertSame('Other core colours available on request.', $save[':note']);
        self::assertSame($save[':note'], $save[':noteInsert']);
        self::assertSame(7, $save[':updatedByUserId']);
        self::assertSame(7, $save[':createdByUserId']);
        self::assertSame(7, $save[':updatedByUserIdInsert']);
    }

    public function test_reads_fall_back_to_defaults_and_saves_explain_when_the_table_is_not_installed(): void
    {
        $connection = new class () implements SqlConnection {
            public function fetchAll(string $sql, array $params = []): array { throw DatabaseUnavailable::fromThrowable(new RuntimeException("Invalid object name 'product.ProductPageContentSettings'.")); }
            public function execute(string $sql, array $params = []): void {}
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function inTransaction(): bool { return false; }
        };
        $repo = new AzureSqlProductPageContentRepository($connection);
        $found = $repo->find(ProductPublicId::fromString(self::PRODUCT));
        self::assertFalse($found->showProductMarking());
        self::assertNull($found->coreColoursNote());
        self::assertTrue($found->showCoreColoursNote());
        $this->expectException(InvalidProductValue::class);
        $repo->save(ProductPublicId::fromString(self::PRODUCT), ProductPageContent::defaults(), UserId::fromInt(1), null);
    }

    public function test_a_stale_expected_version_is_a_concurrency_conflict_and_nothing_is_merged(): void
    {
        $connection = new class () implements SqlConnection {
            /** @var list<string> */
            public array $sql = [];
            public function fetchAll(string $sql, array $params = []): array { $this->sql[] = $sql; return [['Version' => '2026-10-14T01:00:00.0000000']]; }
            public function execute(string $sql, array $params = []): void {}
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function inTransaction(): bool { return false; }
        };
        $repo = new AzureSqlProductPageContentRepository($connection);
        try {
            $repo->save(ProductPublicId::fromString(self::PRODUCT), ProductPageContent::defaults(), UserId::fromInt(1), '2026-10-13T00:00:00.0000000');
            self::fail('expected a concurrency conflict');
        } catch (ProductConcurrencyConflict) {
            self::assertCount(1, $connection->sql);
            self::assertStringNotContainsString('MERGE', $connection->sql[0]);
        }
        // the matching version passes the check and reaches the MERGE
        $repo->save(ProductPublicId::fromString(self::PRODUCT), ProductPageContent::defaults(), UserId::fromInt(1), '2026-10-14T01:00:00.0000000');
        self::assertCount(3, $connection->sql);
        self::assertStringContainsString('MERGE', $connection->sql[2]);
    }

    public function test_a_stored_row_resolves_to_its_explicit_settings_and_version(): void
    {
        $connection = new class () implements SqlConnection {
            public function fetchAll(string $sql, array $params = []): array { return [['ShowProductMarking' => 1, 'CoreColoursNote' => 'N', 'ShowCoreColoursNote' => 0, 'Version' => '2026-10-14T01:00:00.0000000']]; }
            public function execute(string $sql, array $params = []): void {}
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function inTransaction(): bool { return false; }
        };
        $found = (new AzureSqlProductPageContentRepository($connection))->find(ProductPublicId::fromString(self::PRODUCT));
        self::assertTrue($found->showProductMarking());
        self::assertSame('N', $found->coreColoursNote());
        self::assertFalse($found->showCoreColoursNote());
        self::assertSame('2026-10-14T01:00:00.0000000', $found->version());
    }
}
