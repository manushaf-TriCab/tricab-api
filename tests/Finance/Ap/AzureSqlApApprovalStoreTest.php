<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use TriCab\Api\Finance\Ap\AzureSqlApApprovalStore;
use TriCab\Api\Finance\Ap\AzureSqlApAuditStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Finance\Ap\AzureSqlApOutboundStore;
use TriCab\Api\Finance\Ap\InMemoryApApproverDirectory;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\Domain\ApApprovalBatch;
use TriCab\Core\Finance\Ap\Domain\ApApprovalDecision;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Ap\Service\ApApprovalService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;

/**
 * The approvals over `create-finance-invoice-schema.sql`, against a real local SQL Server ({@see LocalFinanceSqlServer}); skipped
 * without one: a unicode round trip, a person id past 2^53, items unique per batch, the first decision winning in one conditional
 * statement, the decisions' stored words, the scope filter before the limit, the request mail's recipients from the mail
 * ledger, the rollback of a failed unit, and a list built in a fixed number of statements.
 */
#[Group('local-sql-server')]
final class AzureSqlApApprovalStoreTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    private AzureSqlApApprovalStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlApApprovalStore($this->connection);
        $invoices = new AzureSqlApInvoiceStore($this->connection);
        $invoices->insert(ApInvoiceFixtures::invoice('a', vendor: 'Acme'));
        $invoices->insert(ApInvoiceFixtures::invoice('b', vendor: 'Bravo'));
        $invoices->insert(ApInvoiceFixtures::invoice('hand', mailbox: null));
        $invoices->insert(ApInvoiceFixtures::invoice('blank', mailbox: '  '));
        $invoices->insert(ApInvoiceFixtures::invoice('mel', mailbox: 'Melissa@Example.test '));
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function batch(string $id, string $at = '2026-10-08 02:03:04', string $note = 'Please check', ?int $user = 7, string $status = 'Pending'): ApApprovalBatch
    {
        return new ApApprovalBatch($id, $user, 'Dee Preparer', 'dee@example.test', $status, $note, ApInvoiceFixtures::utc($at), ApInvoiceFixtures::utc($at));
    }

    /**
     * @param list<string> $invoices
     */
    private function add(ApApprovalBatch $batch, array $invoices): void
    {
        $items = [];
        foreach ($invoices as $n => $invoice) {
            $items[$batch->id . '-i' . $n] = $invoice;
        }
        $this->store->add($batch, $items, $batch->createdAt);
    }

    /**
     * @param list<ApApprovalBatch> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (ApApprovalBatch $b): string => $b->id, $rows);
    }

    /**
     * @param list<string> $to
     */
    private function delivery(string $id, array $to, string $key, string $subject = 'AP approval request: 1 invoice(s)'): FinanceMailDelivery
    {
        return new FinanceMailDelivery($id, 'AP', 7, 'ap@example.test', $to, [], $subject, str_repeat('a', 64), [], $key, FinanceMailRules::STATUS_PENDING, null, ApInvoiceFixtures::utc('2026-10-08 02:00:00'), null);
    }

    /**
     * @param list<string> $to
     */
    private function mailed(string $batchId, string $linkId, string $deliveryId, string $at, array $to, string $key): void
    {
        $this->ledger()->recordPending($this->delivery($deliveryId, $to, $key));
        (new AzureSqlApOutboundStore($this->connection))->add(new ApOutboundMessage($linkId, ApOutboundTarget::approvalBatch($batchId), $deliveryId, 7, '<p>x</p>', false, ApInvoiceFixtures::utc($at)));
    }

    private function ledger(): AzureSqlFinanceMailLedger
    {
        return new AzureSqlFinanceMailLedger($this->connection);
    }

    public function test_a_batch_round_trips_with_unicode_a_long_note_six_digit_time_and_a_person_id_past_two_to_the_53(): void
    {
        $note = str_repeat('Merci — 谢谢 ✓ ', 2000);
        $batch = new ApApprovalBatch('batch1', 9007199254740993, 'Zoë Łukasz 北京', 'zoe@example.test', 'Pending', $note, ApInvoiceFixtures::utc('2026-10-08 02:03:04.123456'), ApInvoiceFixtures::utc('2026-10-08 02:03:05.654321'));
        $this->add($batch, ['a']);

        $read = $this->store->find('batch1');

        self::assertNotNull($read);
        self::assertSame(['batch1', 9007199254740993, 'Zoë Łukasz 北京', 'zoe@example.test', 'Pending', $note], [$read->id, $read->preparedByUserId, $read->preparedByName, $read->preparedByEmail, $read->status, $read->note]);
        self::assertSame('2026-10-08 02:03:04.123456', $read->createdAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-08 02:03:05.654321', $read->updatedAt->format('Y-m-d H:i:s.u'));
        self::assertNull($read->decidedByUserId);
        self::assertNull($read->decidedAt);
        self::assertNull($read->decisionComment);
        self::assertSame('', $read->decidedByName);
        self::assertNull($this->store->find('nope'));
    }

    public function test_items_are_unique_per_batch_and_an_invoice_may_be_in_several_batches(): void
    {
        $this->add(self::batch('b1'), ['a', 'b']);
        $this->add(self::batch('b2'), ['a']);

        self::assertSame(['a', 'b'], $this->store->invoiceIdsOf(['b1'])['b1']);
        self::assertSame(['b1' => ['a', 'b'], 'b2' => ['a']], $this->store->invoiceIdsOf(['b1', 'b2']));

        $this->expectException(ApConflict::class);
        $this->expectExceptionMessage('The invoice is already in this approval request');
        $this->store->add(self::batch('b3'), ['x1' => 'a', 'x2' => 'a'], ApInvoiceFixtures::utc('2026-10-08 02:03:04'));
    }

    public function test_a_failed_add_inside_a_unit_leaves_nothing_behind(): void
    {
        try {
            $this->store->transactional(function (): void {
                $this->add(self::batch('b1'), ['a']);
                $this->add(self::batch('b2'), ['a', 'ghost']);
            });
            self::fail('Expected the foreign key to refuse the unknown invoice.');
        } catch (Throwable $refused) {
            self::assertNotSame('', $refused->getMessage());
        }

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_approval_batches]'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_approval_items]'));
        self::assertNull($this->store->find('b1'));
    }

    public function test_a_status_other_than_pending_approved_or_rejected_is_refused_by_the_check_constraint(): void
    {
        $this->expectException(Throwable::class);
        $this->add(self::batch('b1', status: 'Cancelled'), ['a']);
    }

    public function test_the_first_decision_wins_in_one_conditional_update(): void
    {
        $this->add(self::batch('b1'), ['a']);
        $at = ApInvoiceFixtures::utc('2026-10-09 03:04:05.123456');

        self::assertTrue($this->store->decide('b1', 'Approved', 21, 'Ann Approver', 'Looks right', $at));
        self::assertFalse($this->store->decide('b1', 'Rejected', 22, 'Bob', 'Too late', ApInvoiceFixtures::utc('2026-10-09 03:04:06')));
        self::assertFalse($this->store->decide('b1', 'Approved', 22, 'Bob', 'Again', ApInvoiceFixtures::utc('2026-10-09 03:04:07')));
        self::assertFalse($this->store->decide('nope', 'Approved', 22, 'Bob', '', $at));

        $read = $this->store->find('b1');
        self::assertNotNull($read);
        self::assertSame(['Approved', 21, 'Ann Approver', 'Looks right'], [$read->status, $read->decidedByUserId, $read->decidedByName, $read->decisionComment]);
        self::assertSame('2026-10-09 03:04:05.123456', $read->decidedAt?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-09 03:04:05.123456', $read->updatedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-08 02:03:04.000000', $read->createdAt->format('Y-m-d H:i:s.u'));
    }

    public function test_a_decision_with_no_comment_stores_an_empty_one_not_null(): void
    {
        $this->add(self::batch('b1'), ['a']);

        $this->store->decide('b1', 'Rejected', 21, 'Ann', '', ApInvoiceFixtures::utc('2026-10-09 03:04:05'));

        self::assertSame('', $this->store->find('b1')?->decisionComment);
    }

    public function test_a_decision_row_stores_the_final_status_word_and_reads_back_with_the_people_in_order(): void
    {
        $this->add(self::batch('b1'), ['a']);
        $this->add(self::batch('b2'), ['b']);
        $this->store->addDecision(new ApApprovalDecision('d1', 'b1', 9007199254740993, 'Zoë 北京', 'zoe@example.test', 'Approved', str_repeat('✓ ok ', 500), ApInvoiceFixtures::utc('2026-10-09 03:04:05.123456')));
        $this->store->addDecision(new ApApprovalDecision('d2', 'b2', null, '', '', 'Rejected', '', ApInvoiceFixtures::utc('2026-10-09 03:00:00')));

        $decisions = $this->store->decisionsOf(['b1', 'b2', 'b3']);

        self::assertEqualsCanonicalizing(['b1', 'b2'], array_keys($decisions));
        $one = $decisions['b1'][0];
        self::assertSame(['d1', 'b1', 9007199254740993, 'Zoë 北京', 'zoe@example.test', 'Approved', str_repeat('✓ ok ', 500)], [$one->id, $one->batchId, $one->approverUserId, $one->approverName, $one->approverEmail, $one->decision, $one->comment]);
        self::assertSame('2026-10-09 03:04:05.123456', $one->createdAt->format('Y-m-d H:i:s.u'));
        self::assertNull($decisions['b2'][0]->approverUserId);
        self::assertSame(['Approved', 'Rejected'], $this->column('SELECT [decision] FROM [finance].[invoice_approval_decisions] ORDER BY [id]'));
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $out[] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    public function test_a_decision_for_an_unknown_batch_is_refused_by_the_foreign_key(): void
    {
        $this->expectException(Throwable::class);
        $this->store->addDecision(new ApApprovalDecision('d1', 'ghost', 1, 'A', 'a@example.test', 'Approved', '', ApInvoiceFixtures::utc('2026-10-09 03:04:05')));
    }

    public function test_recent_is_newest_first_by_time_then_id_and_at_most_the_limit(): void
    {
        $this->add(self::batch('b1', '2026-10-08 01:00:00'), ['a']);
        $this->add(self::batch('b3', '2026-10-08 03:00:00'), ['a']);
        $this->add(self::batch('b2', '2026-10-08 03:00:00'), ['a']);
        $this->add(self::batch('b4', '2026-10-08 04:00:00'), ['a']);

        self::assertSame(['b4', 'b3', 'b2', 'b1'], self::ids($this->store->recent(200, null)));
        self::assertSame(['b4', 'b3'], self::ids($this->store->recent(2, null)));
        self::assertSame([], $this->store->recent(0, null));
    }

    public function test_the_scope_hides_a_batch_with_any_invoice_out_of_scope_before_the_limit_counts_and_a_blank_mailbox_is_visible(): void
    {
        $this->add(self::batch('b1', '2026-10-08 01:00:00'), ['a', 'hand']);
        $this->add(self::batch('b2', '2026-10-08 02:00:00'), ['a', 'mel']);
        $this->add(self::batch('b3', '2026-10-08 03:00:00'), ['blank']);
        $this->add(self::batch('b4', '2026-10-08 04:00:00'), ['mel']);

        self::assertSame(['b3', 'b1'], self::ids($this->store->recent(200, ['ap@example.test'])));
        self::assertSame(['b3', 'b1'], self::ids($this->store->recent(2, ['ap@example.test'])), 'the limit counts only visible batches');
        self::assertSame(['b4', 'b3'], self::ids($this->store->recent(2, ['melissa@example.test'])), 'case and spaces do not matter');
        self::assertSame(['b4', 'b3', 'b2', 'b1'], self::ids($this->store->recent(200, ['ap@example.test', 'melissa@example.test'])));
        self::assertSame(['b4', 'b3', 'b2', 'b1'], self::ids($this->store->recent(200, null)));
    }

    public function test_the_request_mail_recipients_come_from_the_ledger_and_the_oldest_request_wins_over_the_outcome(): void
    {
        $this->add(self::batch('b1'), ['a']);
        $this->add(self::batch('b2'), ['b']);
        $this->add(self::batch('b3'), ['b']);
        $this->mailed('b1', 'o2', 'd2', '2026-10-08 03:00:00', ['dee@example.test'], 'ap-approval:b1:outcome');
        $this->mailed('b1', 'o1', 'd1', '2026-10-08 02:00:00', ['Ann@example.test', 'bob@example.test'], 'ap-approval:b1:request');
        $this->mailed('b2', 'o3', 'd3', '2026-10-08 02:30:00', ['dee@example.test'], 'ap-approval:b2:outcome');

        $notified = $this->store->notifiedTo(['b1', 'b2', 'b3']);

        self::assertSame(['b1' => ['Ann@example.test', 'bob@example.test']], $notified, 'only a request mail counts; a batch without one is left out');
    }

    public function test_a_failed_request_mail_still_names_its_recipients(): void
    {
        $this->add(self::batch('b1'), ['a']);
        $this->mailed('b1', 'o1', 'd1', '2026-10-08 02:00:00', ['ann@example.test'], 'ap-approval:b1:request');
        $this->ledger()->markFailed('d1', 'Graph refused it.');

        self::assertSame(['b1' => ['ann@example.test']], $this->store->notifiedTo(['b1']));
    }

    public function test_a_list_is_built_in_a_fixed_number_of_statements_however_many_batches(): void
    {
        $counting = new CountingSqlConnection($this->connection);
        $service = $this->service($counting);

        $this->seedBatches(2);
        $counting->statements = 0;
        $small = $service->list(ApApprovalWorld::preparer());
        $smallStatements = $counting->statements;

        $this->seedBatches(40, 2);
        $counting->statements = 0;
        $large = $service->list(ApApprovalWorld::preparer());
        $largeStatements = $counting->statements;

        self::assertCount(2, $small);
        self::assertCount(42, $large);
        self::assertSame(5, $smallStatements, 'batches, items, decisions, recipients, invoices');
        self::assertSame(5, $largeStatements, 'the same however many batches');
        $counting->statements = 0;
        $service->list(ApApprovalWorld::preparer(['ap@example.test']));
        self::assertSame(5, $counting->statements, 'the scope does not add a statement');
        $counting->statements = 0;
        $service->get(ApApprovalWorld::preparer(), 'c1');
        self::assertSame(5, $counting->statements);
        $invoices = $large[0]['invoices'];
        self::assertIsArray($invoices);
        self::assertIsArray($invoices[0]);
        self::assertSame('Acme', $invoices[0]['vendor']);
    }

    public function test_a_request_and_a_decision_run_through_the_sql_stores_in_one_unit_each(): void
    {
        $world = new ApMailWorld();
        $directory = new InMemoryApApproverDirectory();
        $directory->add(21, 'Ann Approver', 'ann@example.test');
        $audit = new AzureSqlApAuditStore($this->connection);
        $invoices = new AzureSqlApInvoiceStore($this->connection);
        $service = new ApApprovalService(
            $this->store,
            $invoices,
            $directory,
            $audit,
            $world->service,
            new ApTime($world->register->clock, 'Australia/Melbourne'),
            'https://site.example.test',
        );

        $out = $service->request(ApApprovalWorld::preparer(), ['invoice_ids' => ['b', 'a'], 'note' => 'Merci — 谢谢']);

        $batch = $out['batch'];
        $id = $batch['id'];
        self::assertIsString($id);
        self::assertNull($out['warning']);
        self::assertSame('Pending', $batch['status']);
        self::assertSame('Merci — 谢谢', $batch['note']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_approval_items]'));
        self::assertSame('Pending approval', $invoices->find('a')?->approvalStatus);
        self::assertSame('Pending approval', $invoices->find('b')?->approvalStatus);
        self::assertSame('Not requested', $invoices->find('hand')?->approvalStatus);

        $decided = $service->decide(ApApprovalWorld::approver(), $id, ['decision' => 'reject', 'comment' => 'Wrong ✗']);

        self::assertSame('Rejected', $decided['status']);
        self::assertNull($decided['notification_warning']);
        self::assertSame(['Rejected'], $this->column('SELECT [decision] FROM [finance].[invoice_approval_decisions]'));
        self::assertSame(['Rejected', 'Rejected'], [$invoices->find('a')->approvalStatus, $invoices->find('b')->approvalStatus]);
        self::assertSame('In Progress', $invoices->find('a')->status, 'the workflow status is not touched');
        $decidedBy = $decided['decided_by_user'];
        self::assertIsArray($decidedBy);
        self::assertSame(['21', 'Ann Approver', 'ann@example.test'], [$decidedBy['id'], $decidedBy['full_name'], $decidedBy['email']]);

        try {
            $service->decide(ApApprovalWorld::approver(null, 22, 'Bob Approver', 'bob@example.test'), $id, ['decision' => 'approve']);
            self::fail('Expected the second decision to be refused.');
        } catch (ApConflict $conflict) {
            self::assertSame('This request was already rejected', $conflict->getMessage());
            self::assertSame($id, self::batchOf($conflict)['id']);
        }
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_approval_decisions]'));
        self::assertSame('Rejected', $invoices->find('a')->approvalStatus);
        self::assertEqualsCanonicalizing(['approval_requested', 'approval_rejected'], array_map(static fn ($e): string => $e->action, $audit->forEntity('approval_batch', $id)), 'both events are there (the fixed clock gives them one time)');
    }

    /**
     * @return array<string, mixed>
     */
    private static function batchOf(ApConflict $conflict): array
    {
        $batch = $conflict->extra['batch'] ?? null;
        self::assertIsArray($batch);
        $typed = [];
        foreach ($batch as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private function seedBatches(int $count, int $from = 0): void
    {
        for ($n = $from; $n < $from + $count; ++$n) {
            $this->add(self::batch('c' . ($n + 1), sprintf('2026-10-08 %02d:%02d:00', intdiv($n, 60), $n % 60)), ['a', 'b']);
            $this->store->addDecision(new ApApprovalDecision('dd' . $n, 'c' . ($n + 1), 1, 'A', 'a@example.test', 'Approved', '', ApInvoiceFixtures::utc('2026-10-09 00:00:00')));
        }
    }

    private function service(CountingSqlConnection $connection): ApApprovalService
    {
        $world = new ApMailWorld();

        return new ApApprovalService(
            new AzureSqlApApprovalStore($connection),
            new AzureSqlApInvoiceStore($connection),
            new InMemoryApApproverDirectory(),
            new AzureSqlApAuditStore($connection),
            $world->service,
            new ApTime($world->register->clock, 'Australia/Melbourne'),
            'https://site.example.test',
        );
    }
}
