<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use TriCab\Api\Finance\Ar\AzureSqlArOutboundStore;
use TriCab\Api\Finance\Ar\AzureSqlArRecordStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ar\Domain\ArOutboundMessage;

/**
 * The composer's link rows over `create-finance-ar-schema.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one: a unicode round trip with a long body, six-digit time, a person
 * id past 2^53, the order, and the foreign key to the record.
 */
#[Group('local-sql-server')]
final class AzureSqlArOutboundStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlArOutboundStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlArOutboundStore($connection);
        $records = new AzureSqlArRecordStore($connection);
        $records->insert(ArFixtures::record('a'));
        $records->insert(ArFixtures::record('b'));
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_a_link_round_trips_with_unicode_a_long_body_six_digit_time_and_a_person_id_past_two_to_the_53(): void
    {
        $body = str_repeat('Merci — 谢谢 ✓ ', 2000);
        $this->store->addOutbound(new ArOutboundMessage('o1', 'a', 'd1', 9007199254740993, 'Zoë@example.test', true, $body, ArFixtures::utc('2026-10-08 02:03:04.123456')));

        $rows = $this->store->outboundOf('a');

        self::assertCount(1, $rows);
        self::assertSame(['o1', 'a', 'd1', 9007199254740993, 'Zoë@example.test', true, $body], [$rows[0]->id, $rows[0]->recordId, $rows[0]->deliveryId, $rows[0]->senderUserId, $rows[0]->senderUser, $rows[0]->threaded, $rows[0]->body]);
        self::assertSame('2026-10-08 02:03:04.123456', $rows[0]->createdAt->format('Y-m-d H:i:s.u'));
    }

    public function test_a_link_with_no_person_reads_back_as_null_and_a_standalone_send_as_not_threaded(): void
    {
        $this->store->addOutbound(new ArOutboundMessage('o1', 'a', 'd1', null, '', false, 'Hello', ArFixtures::utc('2026-10-08 02:00:00')));

        $row = $this->store->outboundOf('a')[0];

        self::assertNull($row->senderUserId);
        self::assertFalse($row->threaded);
    }

    public function test_a_record_lists_only_its_own_links_newest_first(): void
    {
        $this->store->addOutbound(new ArOutboundMessage('o1', 'a', 'd1', 1, 'x', false, 'First', ArFixtures::utc('2026-10-08 01:00:00')));
        $this->store->addOutbound(new ArOutboundMessage('o2', 'a', 'd2', 1, 'x', false, 'Second', ArFixtures::utc('2026-10-08 02:00:00')));
        $this->store->addOutbound(new ArOutboundMessage('o3', 'b', 'd3', 1, 'x', false, 'Other record', ArFixtures::utc('2026-10-08 03:00:00')));

        self::assertSame(['o2', 'o1'], array_map(static fn (ArOutboundMessage $m): string => $m->id, $this->store->outboundOf('a')));
        self::assertSame(['o3'], array_map(static fn (ArOutboundMessage $m): string => $m->id, $this->store->outboundOf('b')));
        self::assertSame([], $this->store->outboundOf('nobody'));
    }

    public function test_a_link_for_a_record_that_does_not_exist_is_refused_by_the_foreign_key_and_the_ledger_has_none(): void
    {
        try {
            $this->store->addOutbound(new ArOutboundMessage('o1', 'ghost', 'd1', 1, 'x', false, 'Hi', ArFixtures::utc('2026-10-08 01:00:00')));
            self::fail('Expected the foreign key to refuse it.');
        } catch (Throwable $refused) {
            self::assertNotSame('', $refused->getMessage());
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_outbound_messages]'));
        // The delivery id names a ledger row but is not a key to it: any id is accepted for a real record.
        $this->store->addOutbound(new ArOutboundMessage('o2', 'a', 'no-such-delivery', 1, 'x', false, 'Hi', ArFixtures::utc('2026-10-08 01:00:00')));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_outbound_messages]'));
    }
}
