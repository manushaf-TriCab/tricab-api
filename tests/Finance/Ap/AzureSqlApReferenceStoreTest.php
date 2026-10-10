<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap\AzureSqlApReferenceStore;
use TriCab\Api\Finance\Ap\AzureSqlApSigningLimitStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;
use TriCab\Core\Finance\Ap\Service\ApSigningAuthorityService;
use TriCab\Core\Finance\Ap\Service\ApTime;

/**
 * The Settings lists and the signing limits over `create-finance-invoice-schema.sql`, against a real
 * local SQL Server ({@see LocalFinanceSqlServer}); skipped without one: the service's whole cycle on
 * the real tables, the unique keys, widths, ordering, a unicode round trip and a limit read back.
 */
#[Group('local-sql-server')]
final class AzureSqlApReferenceStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlApReferenceStore $store;

    private ApReferenceService $service;

    private PdoSqlConnection $connection;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlApReferenceStore($this->connection);
        $this->service = new ApReferenceService($this->store, new ApTime(FixedClock::utc('2026-10-09 01:02:03.456789'), 'Australia/Melbourne'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function id(array $row): string
    {
        self::assertIsString($row['id']);

        return $row['id'];
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 01:02:03.456789', new DateTimeZone('UTC'));
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_a_company_round_trips_through_the_real_table_with_unicode_and_microseconds(): void
    {
        $created = $this->service->create('companies', ['name' => 'Tri-Cab Pty Ltd — Café', 'code' => 'TRI', 'sites' => 'Somerton, Dandenong', 'former_names' => 'Old Pty Ltd']);

        $rows = $this->service->list('companies');

        self::assertSame([$created], $rows);
        self::assertSame('Tri-Cab Pty Ltd — Café', $rows[0]['name']);
        self::assertSame('2026-10-09T01:02:03.456789+00:00', $rows[0]['created_date']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_companies] WHERE [is_active] = 1'));
    }

    public function test_the_three_lists_run_their_whole_cycle_on_their_own_tables(): void
    {
        foreach ([
            ['companies', 'invoice_companies', ['name' => 'TriCab'], ['name' => 'TriCab Group']],
            ['cost-centres', 'invoice_cost_centres', ['code' => 'CC1', 'code_no' => '1', 'name' => 'Warehouse'], ['code_no' => '2']],
            ['account-categories', 'invoice_account_categories', ['code' => 'FREIGHT', 'name' => 'Freight'], ['name' => 'Inbound']],
        ] as [$list, $table, $create, $patch]) {
            $row = $this->service->create($list, $create);
            self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[' . $table . ']'), $list);

            $patched = $this->service->update($list, self::id($row), $patch + ['is_active' => false]);
            foreach ($patch as $field => $value) {
                self::assertSame($value, $patched[$field], $list);
            }
            self::assertFalse($patched['is_active']);
            self::assertSame($patched, $this->service->list($list)[0], $list);
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[' . $table . '] WHERE [is_active] = 1'));

            $this->service->delete($list, self::id($row));
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[' . $table . ']'), $list);
        }
    }

    public function test_the_unique_keys_refuse_a_duplicate_as_a_conflict_ignoring_case(): void
    {
        $this->service->create('companies', ['name' => 'TriCab']);
        $this->service->create('cost-centres', ['code' => 'CC1']);
        $this->service->create('account-categories', ['code' => 'F']);

        foreach ([['companies', ['name' => 'tricab'], 'A company with that name already exists'], ['cost-centres', ['code' => 'cc1'], 'A cost centre with that code already exists'], ['account-categories', ['code' => 'f'], 'An account category with that code already exists']] as [$list, $body, $message]) {
            try {
                $this->service->create($list, $body);
                self::fail($list . ' should refuse a duplicate.');
            } catch (ApConflict $failure) {
                self::assertSame($message, $failure->getMessage());
            }
        }
        // A rename onto another row's value is the same refusal; renaming to itself is not.
        $other = $this->service->create('cost-centres', ['code' => 'CC2']);
        try {
            $this->service->update('cost-centres', self::id($other), ['code' => 'CC1']);
            self::fail('A rename onto an existing code should be refused.');
        } catch (ApConflict) {
        }
        $kept = $this->service->update('cost-centres', self::id($other), ['code' => 'CC2', 'name' => 'Same']);
        self::assertSame('Same', $kept['name']);
    }

    public function test_a_company_code_is_not_unique_but_its_name_is(): void
    {
        $this->service->create('companies', ['name' => 'One', 'code' => 'SAME']);
        $this->service->create('companies', ['name' => 'Two', 'code' => 'SAME']);

        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_companies]'));
    }

    public function test_lists_order_on_the_column_with_the_limit_and_fall_back_on_created_date(): void
    {
        foreach (['Charlie', 'alpha', 'Bravo'] as $name) {
            $this->service->create('companies', ['name' => $name]);
        }

        self::assertSame(['alpha', 'Bravo', 'Charlie'], array_column($this->service->list('companies', 'name'), 'name'), 'the collation is case-insensitive');
        self::assertSame(['Charlie', 'Bravo'], array_column($this->service->list('companies', '-name', 2), 'name'));
        self::assertCount(3, $this->service->list('companies', 'sites'), 'long text is not sortable and falls back');
        self::assertCount(3, $this->service->list('companies', 'name; DROP TABLE x'));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_companies]'));
    }

    public function test_the_widest_values_the_service_allows_fit_the_columns(): void
    {
        $row = $this->service->create('companies', ['name' => str_repeat('é', 200), 'code' => str_repeat('c', 50), 'sites' => str_repeat('s', 2000), 'former_names' => str_repeat('f', 2000)]);

        $read = $this->service->list('companies')[0];
        self::assertSame($row, $read);
        self::assertIsString($read['sites']);
        self::assertSame(2000, mb_strlen($read['sites']));
        try {
            $this->service->create('companies', ['name' => 'Too wide', 'code' => str_repeat('c', 51)]);
            self::fail('The service should refuse a code wider than its column.');
        } catch (ApInvalid) {
        }
    }

    public function test_find_and_delete_of_an_unknown_id_are_quiet_and_an_unknown_patch_is_not_found(): void
    {
        $spec = ApReferenceSpec::for('companies');
        self::assertNull($this->store->find($spec, str_repeat('0', 32)));
        $this->store->delete($spec, str_repeat('0', 32));
        try {
            $this->service->update('companies', str_repeat('0', 32), ['name' => 'X']);
            self::fail('Expected a 404.');
        } catch (ApNotFound) {
        }
        $row = new ApReferenceRow('companies', str_repeat('1', 32), ['code' => '', 'name' => 'Direct', 'sites' => '', 'former_names' => ''], true, self::at());
        $this->store->insert($row);
        self::assertEquals($row, $this->store->find($spec, str_repeat('1', 32)));
    }

    public function test_signing_limits_read_back_with_decimals_nulls_and_unlimited(): void
    {
        $this->pdo->exec("INSERT INTO [finance].[invoice_signing_limits] ([id], [authority_role], [role_code], [full_name], [email], [d365_position], [po_inventory], [po_non_inventory], [expense_claim], [customer_order], [credit_notes], [is_active])
            VALUES ('" . str_repeat('a', 32) . "', N'CFO', N'CFO', N'Zed Zane', N'zed@example.test', N'Chief Financial Officer', 99999999999.00, 12345.67, NULL, 0, 250.50, 1),
                   ('" . str_repeat('b', 32) . "', N'Manager', N'MGR', N'Amy Adams', N'amy@example.test', N'Finance Manager', 1000, 500, 100, NULL, NULL, 1),
                   ('" . str_repeat('c', 32) . "', N'Manager', N'MGR', N'Vacant', N'', N'Vacant Seat', 1, 1, 1, 1, 1, 1),
                   ('" . str_repeat('d', 32) . "', N'Manager', N'MGR', N'Gone Gail', N'gail@example.test', N'Retired Seat', 1, 1, 1, 1, 1, 0)");

        $rows = (new ApSigningAuthorityService(new AzureSqlApSigningLimitStore($this->connection)))->list();

        self::assertSame(['Amy Adams', 'Zed Zane'], array_column($rows, 'name'));
        self::assertSame(['po_inventory' => 1000.0, 'po_non_inventory' => 500.0, 'expense_claim' => 100.0, 'customer_order' => null, 'credit_notes' => null], $rows[0]['limits']);
        self::assertSame(['po_inventory' => 99999999999.0, 'po_non_inventory' => 12345.67, 'expense_claim' => null, 'customer_order' => 0.0, 'credit_notes' => 250.5], $rows[1]['limits']);
        self::assertSame('Chief Financial Officer', $rows[1]['position']);
    }
}
