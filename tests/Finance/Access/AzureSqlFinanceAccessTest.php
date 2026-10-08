<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Access;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\AzureSqlFinanceAccessScopeReader;
use TriCab\Api\Finance\Access\AzureSqlFinancePortalUserMap;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;

/**
 * The two tables of `create-finance-access-schema.sql` over a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one.
 */
#[Group('local-sql-server')]
final class AzureSqlFinanceAccessTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private PDO $pdo;

    private AzureSqlFinanceAccessScopeReader $scopes;

    private AzureSqlFinancePortalUserMap $map;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->scopes = new AzureSqlFinanceAccessScopeReader($connection);
        $this->map = new AzureSqlFinancePortalUserMap($connection);
    }

    private function scope(int $user, string $app, string $type, string $value): void
    {
        $this->pdo->prepare('INSERT INTO [finance].[access_scopes] ([user_id], [application_key], [scope_type], [scope_value]) VALUES (?, ?, ?, ?)')
            ->execute([$user, $app, $type, $value]);
    }

    private static function mapping(string $portal, string $email, int $platform): FinancePortalUserMapping
    {
        return new FinancePortalUserMapping($portal, $email, $platform, new DateTimeImmutable('2026-10-07 02:03:04.567891', new DateTimeZone('UTC')));
    }

    public function test_no_row_means_unrestricted_and_rows_mean_exactly_those_values(): void
    {
        self::assertTrue($this->scopes->forUser(7, 'AP')->isUnrestricted());

        $this->scope(7, 'AP', 'mailbox', 'AP@Tricab.com');
        $this->scope(7, 'AP', 'mailbox', 'invoices@tricab.com');
        $this->scope(7, 'CREDIT', 'branch', 'Melbourne');
        $this->scope(8, 'AP', 'mailbox', 'other@tricab.com');

        $ap = $this->scopes->forUser(7, 'AP');
        self::assertSame(['ap@tricab.com', 'invoices@tricab.com'], $ap->mailboxes);
        self::assertTrue($ap->allowsMailbox('INVOICES@tricab.com'));
        self::assertFalse($ap->allowsMailbox('other@tricab.com'));
        self::assertTrue($ap->allowsBranch('Anywhere'));
        self::assertSame(['Melbourne'], $this->scopes->forUser(7, 'CREDIT')->branches);
        self::assertTrue($this->scopes->forUser(7, 'TASK')->isUnrestricted());
        self::assertSame(['other@tricab.com'], $this->scopes->forUser(8, 'AP')->mailboxes);
    }

    public function test_the_table_refuses_an_unknown_app_kind_or_empty_value_and_a_repeated_row(): void
    {
        $this->scope(7, 'AP', 'mailbox', 'ap@tricab.com');

        foreach ([[7, 'WIDGET', 'branch', 'x'], [7, 'AP', 'region', 'x'], [7, 'AP', 'branch', '   '], [7, 'AP', 'mailbox', 'ap@tricab.com']] as [$user, $app, $type, $value]) {
            try {
                $this->scope($user, $app, $type, $value);
                self::fail('Expected the table to refuse ' . $app . ' ' . $type . ' ' . $value);
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_the_map_adds_reads_and_refuses_a_second_row_for_either_side(): void
    {
        self::assertNull($this->map->platformUserIdFor(self::A));

        self::assertTrue($this->map->add(self::mapping(self::A, 'a@tricab.com', 1)));
        self::assertFalse($this->map->add(self::mapping(self::A, 'a@tricab.com', 2)), 'the portal user is already mapped');
        self::assertFalse($this->map->add(self::mapping(self::B, 'b@tricab.com', 1)), 'the platform user is already mapped');
        self::assertTrue($this->map->add(self::mapping(self::B, 'b@tricab.com', 2)));

        self::assertSame(1, $this->map->platformUserIdFor(self::A));
        $all = $this->map->all();
        self::assertSame([self::A, self::B], array_keys($all));
        self::assertSame('a@tricab.com', $all[self::A]->recordedEmail);
        self::assertSame('2026-10-07 02:03:04.567891', $all[self::A]->mappedAt->format('Y-m-d H:i:s.u'));
    }

    public function test_the_unique_index_is_what_stops_two_portal_users_sharing_a_platform_user(): void
    {
        $this->pdo->exec("INSERT INTO [finance].[portal_user_map] ([portal_user_id], [recorded_email], [platform_user_id]) VALUES (N'" . self::A . "', N'a@tricab.com', 5)");

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO [finance].[portal_user_map] ([portal_user_id], [recorded_email], [platform_user_id]) VALUES (N'" . self::B . "', N'b@tricab.com', 5)");
    }
}
