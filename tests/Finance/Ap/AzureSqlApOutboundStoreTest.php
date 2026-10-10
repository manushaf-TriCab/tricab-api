<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use TriCab\Api\Finance\Ap\AzureSqlApEmailStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Finance\Ap\AzureSqlApOutboundStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;

/**
 * The sent-mail links over `create-finance-invoice-schema.sql` (decision D73), against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one: a unicode round trip with a long body, six-digit time, a person id past
 * 2^53, the three kinds of record, the order and limit, the foreign keys, and the lookup by delivery.
 */
#[Group('local-sql-server')]
final class AzureSqlApOutboundStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlApOutboundStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlApOutboundStore($connection);
        (new AzureSqlApInvoiceStore($connection))->insert(ApInvoiceFixtures::invoice('a'));
        (new AzureSqlApInvoiceStore($connection))->insert(ApInvoiceFixtures::invoice('b'));
        $emails = new AzureSqlApEmailStore($connection);
        $emails->insert(new ApEmail('e', 'graph-e', 'ap@example.test', ApEmailWorld::utc('2026-10-05 02:00:00'), ApEmailWorld::utc('2026-10-05 02:00:00')));
        $this->pdo->exec("INSERT INTO [finance].[invoice_approval_batches] ([id]) VALUES ('batch1')");
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function link(string $id, ApOutboundTarget $target, string $delivery, string $at, string $body = 'Hello', bool $attach = false, ?int $user = 1): ApOutboundMessage
    {
        return new ApOutboundMessage($id, $target, $delivery, $user, $body, $attach, ApInvoiceFixtures::utc($at));
    }

    /**
     * @param list<ApOutboundMessage> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (ApOutboundMessage $m): string => $m->id, $rows);
    }

    public function test_a_link_round_trips_with_unicode_a_long_body_six_digit_time_and_a_person_id_past_two_to_the_53(): void
    {
        $body = str_repeat('Merci — 谢谢 ✓ ', 2000);
        $this->store->add(self::link('o1', ApOutboundTarget::invoice('a'), 'd1', '2026-10-08 02:03:04.123456', $body, true, 9007199254740993));

        $rows = $this->store->of(ApOutboundTarget::invoice('a'), 10);

        self::assertCount(1, $rows);
        self::assertSame(['o1', 'invoice', 'a', 'd1', 9007199254740993, $body, true], [$rows[0]->id, $rows[0]->target->kind, $rows[0]->target->id, $rows[0]->deliveryId, $rows[0]->senderUserId, $rows[0]->body, $rows[0]->attachInvoice]);
        self::assertSame('2026-10-08 02:03:04.123456', $rows[0]->createdAt->format('Y-m-d H:i:s.u'));
    }

    public function test_a_link_with_no_person_reads_back_as_null_and_the_flag_defaults_off(): void
    {
        $this->store->add(self::link('o1', ApOutboundTarget::email('e'), 'd1', '2026-10-08 02:00:00', 'Hi', false, null));

        $row = $this->store->of(ApOutboundTarget::email('e'), 10)[0];

        self::assertNull($row->senderUserId);
        self::assertFalse($row->attachInvoice);
        self::assertSame(['email', 'e'], [$row->target->kind, $row->target->id]);
    }

    public function test_each_kind_of_record_lists_only_its_own_newest_first_and_at_most_the_limit(): void
    {
        $this->store->add(self::link('o1', ApOutboundTarget::invoice('a'), 'd1', '2026-10-08 01:00:00'));
        $this->store->add(self::link('o2', ApOutboundTarget::invoice('a'), 'd2', '2026-10-08 02:00:00'));
        $this->store->add(self::link('o3', ApOutboundTarget::invoice('a'), 'd3', '2026-10-08 03:00:00'));
        $this->store->add(self::link('o4', ApOutboundTarget::invoice('b'), 'd4', '2026-10-08 04:00:00'));
        $this->store->add(self::link('o5', ApOutboundTarget::email('e'), 'd5', '2026-10-08 05:00:00'));
        $this->store->add(self::link('o6', ApOutboundTarget::approvalBatch('batch1'), 'd6', '2026-10-08 06:00:00', '<p>HTML</p>'));

        self::assertSame(['o3', 'o2', 'o1'], self::ids($this->store->of(ApOutboundTarget::invoice('a'), 100)));
        self::assertSame(['o3', 'o2'], self::ids($this->store->of(ApOutboundTarget::invoice('a'), 2)));
        self::assertSame(['o4'], self::ids($this->store->of(ApOutboundTarget::invoice('b'), 100)));
        self::assertSame(['o5'], self::ids($this->store->of(ApOutboundTarget::email('e'), 50)));
        $batch = $this->store->of(ApOutboundTarget::approvalBatch('batch1'), 100);
        self::assertSame(['o6', 'batch1', '<p>HTML</p>'], [$batch[0]->id, $batch[0]->target->id, $batch[0]->body]);
        self::assertSame([], $this->store->of(ApOutboundTarget::invoice('nobody'), 100));
    }

    public function test_equal_times_order_by_id(): void
    {
        $this->store->add(self::link('o2', ApOutboundTarget::invoice('a'), 'd2', '2026-10-08 01:00:00'));
        $this->store->add(self::link('o1', ApOutboundTarget::invoice('a'), 'd1', '2026-10-08 01:00:00'));

        self::assertSame(['o1', 'o2'], self::ids($this->store->of(ApOutboundTarget::invoice('a'), 100)));
    }

    public function test_a_link_is_found_by_its_delivery(): void
    {
        $this->store->add(self::link('o1', ApOutboundTarget::invoice('a'), 'd1', '2026-10-08 01:00:00'));
        $this->store->add(self::link('o2', ApOutboundTarget::email('e'), 'd2', '2026-10-08 02:00:00'));

        self::assertSame('o2', $this->store->findByDelivery('d2')?->id);
        $found = $this->store->findByDelivery('d2');
        self::assertSame(['email', 'e'], [$found->target->kind, $found->target->id]);
        self::assertNull($this->store->findByDelivery('nope'));
    }

    public function test_a_link_for_a_record_that_does_not_exist_is_refused_by_the_foreign_key_and_the_ledger_needs_no_row(): void
    {
        foreach ([ApOutboundTarget::invoice('ghost'), ApOutboundTarget::email('ghost'), ApOutboundTarget::approvalBatch('ghost')] as $target) {
            try {
                $this->store->add(self::link('o1', $target, 'd1', '2026-10-08 01:00:00'));
                self::fail('Expected the foreign key to refuse it.');
            } catch (Throwable $refused) {
                self::assertNotSame('', $refused->getMessage());
            }
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_outbound_messages]'));
        // The delivery id names a ledger row but is not a key to it: any id is accepted for a real record.
        $this->store->add(self::link('o2', ApOutboundTarget::invoice('a'), 'no-such-delivery', '2026-10-08 01:00:00'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_outbound_messages]'));
    }

    public function test_the_table_allows_one_record_column_only_through_the_stores_inserts(): void
    {
        $this->store->add(self::link('o1', ApOutboundTarget::invoice('a'), 'd1', '2026-10-08 01:00:00'));

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_outbound_messages] WHERE [invoice_id] = 'a' AND [email_id] IS NULL AND [approval_batch_id] IS NULL"));
    }

    public function test_work_in_a_transaction_commits_together_and_rolls_back_on_failure(): void
    {
        $this->store->transactional(function (): void {
            $this->store->add(self::link('o1', ApOutboundTarget::invoice('a'), 'd1', '2026-10-08 01:00:00'));
        });
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_outbound_messages]'));

        try {
            $this->store->transactional(function (): void {
                $this->store->add(self::link('o2', ApOutboundTarget::invoice('a'), 'd2', '2026-10-08 02:00:00'));
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_outbound_messages]'));
    }
}
