<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\AzureSqlArOptionStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArTime;

/**
 * The Category and Method lists over `create-finance-ar-schema.sql`, against a real local SQL
 * Server ({@see LocalFinanceSqlServer}); skipped without one: seeding, the primary key, the shifts
 * and orders, usage counts over real records, a transaction, and a unicode round trip.
 */
#[Group('local-sql-server')]
final class AzureSqlArOptionStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlArOptionStore $store;

    private ArOptionService $service;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->store = new AzureSqlArOptionStore(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
        $this->service = new ArOptionService($this->store, new ArTime(FixedClock::utc('2026-10-08 01:02:03.456789'), 'Australia/Melbourne'));
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-08 01:02:03.456789', new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function insertRecord(string $id, string $category, string $method, bool $binned = false): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [finance].[ar_email_records] ([id], [email_date], [sender_email], [subject], [follow_up_category], [proposed_response_type], [deleted_at])
             VALUES (:id, SYSUTCDATETIME(), N\'c@example.test\', N\'Subject\', :category, :method, ' . ($binned ? 'SYSUTCDATETIME()' : 'NULL') . ')',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['id' => $id, 'category' => $category, 'method' => $method]);
    }

    public function test_an_empty_list_is_seeded_once_with_the_defaults_in_order(): void
    {
        $this->service->lists();
        $this->service->lists();

        self::assertSame(12, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_option_lists] WHERE [list_name] = N'category'"));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_option_lists] WHERE [list_name] = N'method'"));
        $options = $this->store->options('category');
        self::assertSame('remittance_advice', $options[0]->value);
        self::assertSame('other', $options[11]->value);
        self::assertTrue($options[11]->isSystem);
        self::assertFalse($options[0]->isSystem);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_option_lists] WHERE [is_system] = 1 AND [list_name] = N'category'"));
        self::assertSame(['phone', 'email'], array_map(static fn (ArOption $o): string => $o->value, $this->store->options('method')));
    }

    public function test_seeding_leaves_a_list_that_has_rows_alone(): void
    {
        $this->store->insertOption(new ArOption('method', 'letter', 'Letter', '', 'slate', 0, true, false, self::at(), self::at()));

        $this->service->lists();

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_option_lists] WHERE [list_name] = N'method'"), 'a list with rows is not seeded');
        self::assertSame(12, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_option_lists] WHERE [list_name] = N'category'"), 'the other, empty list is');
    }

    public function test_the_primary_key_refuses_a_duplicate_and_a_failed_seed_leaves_nothing_half_written(): void
    {
        $option = new ArOption('category', 'x', 'X', '', 'slate', 0, true, false, self::at(), self::at());
        $this->store->insertOption($option);

        try {
            $this->store->insertOption($option);
            self::fail('The primary key should refuse a repeat.');
        } catch (ArConflict) {
        }

        // A transaction holding an insert and then a clash rolls both back.
        try {
            $this->store->transactional(function () use ($option): void {
                $this->store->insertOption($option->with(value: 'y', label: 'Y'));
                $this->store->insertOption($option);
            });
            self::fail('The clash should abort the transaction.');
        } catch (ArConflict) {
        }
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_option_lists] WHERE [list_name] = N'category'"));
    }

    public function test_the_list_name_check_refuses_an_unknown_list(): void
    {
        $this->expectException(\Throwable::class);
        $this->store->insertOption(new ArOption('nonsense', 'x', 'X', '', 'slate', 0, true, false, self::at(), self::at()));
    }

    public function test_create_shifts_other_down_and_the_orders_stay_contiguous(): void
    {
        $this->service->create('category', ['label' => 'Warranty Claim', 'description' => 'A claim', 'color' => 'orange']);
        $this->service->create('category', ['label' => 'Freight Query']);

        $options = $this->store->options('category');

        self::assertSame(range(0, 13), array_map(static fn (ArOption $o): int => $o->sortOrder, $options));
        self::assertSame('other', $options[13]->value);
        self::assertSame(['warranty_claim', 'freight_query'], [$options[11]->value, $options[12]->value]);
    }

    public function test_reorder_and_update_write_what_the_service_decides_and_not_the_creation_time(): void
    {
        $this->service->create('method', ['label' => 'Letter']);
        $before = $this->store->findOption('method', 'phone');
        self::assertInstanceOf(ArOption::class, $before);

        $this->service->reorder('method', ['letter', 'email', 'phone']);
        $this->service->update('method', 'phone', ['label' => 'Phone call', 'description' => 'By voice', 'color' => 'rose']);

        $after = $this->store->findOption('method', 'phone');
        self::assertInstanceOf(ArOption::class, $after);
        self::assertSame(2, $after->sortOrder);
        self::assertSame('Phone call', $after->label);
        self::assertSame('By voice', $after->description);
        self::assertSame('rose', $after->color);
        self::assertTrue($after->isSystem);
        self::assertEquals($before->createdAt, $after->createdAt);
        self::assertSame(['letter', 'email', 'phone'], array_map(static fn (ArOption $o): string => $o->value, $this->store->options('method')));
    }

    public function test_usage_counts_every_record_binned_ones_included_and_a_used_option_is_archived(): void
    {
        $this->service->create('method', ['label' => 'Teams Message']);
        $this->insertRecord(str_repeat('a', 32), 'other', 'teams_message');
        $this->insertRecord(str_repeat('b', 32), 'credit_notes', 'teams_message', true);
        $this->insertRecord(str_repeat('c', 32), 'credit_notes', 'email');

        self::assertSame(['credit_notes' => 2, 'other' => 1], self::sorted($this->store->usage('category')));
        self::assertSame(['email' => 1, 'teams_message' => 2], self::sorted($this->store->usage('method')));
        self::assertSame(['deleted' => false, 'archived' => true, 'usage_count' => 2], $this->service->delete('method', 'teams_message'));
        $archived = $this->store->findOption('method', 'teams_message');
        self::assertInstanceOf(ArOption::class, $archived);
        self::assertFalse($archived->active);

        $this->service->create('method', ['label' => 'Letter']);
        self::assertSame(['deleted' => true, 'archived' => false, 'usage_count' => 0], $this->service->delete('method', 'letter'));
        self::assertNull($this->store->findOption('method', 'letter'));
        self::assertSame([], $this->store->usage('nonsense'));
    }

    public function test_unicode_and_six_digit_time_round_trip(): void
    {
        $label = 'Garantie Überprüfung — 保証';
        $this->store->insertOption(new ArOption('category', 'garantie', $label, 'Beschreibung: ñandú ✓', 'teal', 0, true, false, self::at(), self::at()));

        $found = $this->store->findOption('category', 'garantie');

        self::assertInstanceOf(ArOption::class, $found);
        self::assertSame($label, $found->label);
        self::assertSame('Beschreibung: ñandú ✓', $found->description);
        self::assertSame('2026-10-08 01:02:03.456789', $found->createdAt->format('Y-m-d H:i:s.u'));
    }

    public function test_a_value_is_matched_without_regard_to_case_by_the_database_but_slugs_are_lower_case(): void
    {
        $this->service->create('category', ['label' => 'Warranty Claim']);

        self::assertInstanceOf(ArOption::class, $this->store->findOption('category', 'warranty_claim'));
        self::assertSame('warranty_claim', ArOptionService::slug('WARRANTY CLAIM'));
    }
}
