<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use TriCab\Api\Finance\Ar\AzureSqlArCreditNoteStore;
use TriCab\Api\Finance\Ar\AzureSqlArRecordStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;

/**
 * Credit-note requests over `create-finance-ar-schema.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one. What the twin cannot prove: the unique token hash, the status
 * check, money to the cent, unicode and six-digit time, the two conditional updates that decide who won (a rep's
 * submit, a new token), and the escaped, ordered, bounded list.
 */
#[Group('local-sql-server')]
final class AzureSqlArCreditNoteStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlArCreditNoteStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->store = new AzureSqlArCreditNoteStore(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
    }

    private function note(string $id, mixed ...$over): ArCreditNote
    {
        $at = ArFixtures::utc('2026-10-08 02:03:04.123456');
        $note = new ArCreditNote(
            $id, null, 'Zoë Łukasz 北京 Pty Ltd', 'ap@example.test', 'Riley Rep', 'rep@example.test', 'INV-1', 12.35, "Damaged — 保証\nsecond line", str_repeat('Please credit it. ', 1500),
            hash('sha256', 'token-' . $id), $at->modify('+72 hours'), ArCreditNote::FORM_SENT, $at, null, null, $at, $at,
        );

        return $over === [] ? $note : $note->with(...$over);
    }

    /**
     * @param array{0: list<ArCreditNote>, 1: int} $page
     * @return list<string>
     */
    private function ids(array $page): array
    {
        return array_map(static fn (ArCreditNote $n): string => $n->id, $page[0]);
    }

    private function statusOf(string $id): string
    {
        $statement = $this->pdo->prepare('SELECT [status] FROM [finance].[ar_credit_note_requests] WHERE [id] = :id');
        $statement->execute(['id' => $id]);

        return (string) $statement->fetchColumn();
    }

    public function test_a_request_round_trips_with_unicode_a_long_text_money_to_the_cent_and_six_digit_time(): void
    {
        $this->store->insertCreditNote($this->note('a'));

        $found = $this->store->findCreditNote('a');

        self::assertNotNull($found);
        self::assertSame(['Zoë Łukasz 北京 Pty Ltd', 12.35, "Damaged — 保証\nsecond line", 27000, null, 'form_sent'], [$found->customerName, $found->creditAmount, $found->reason, strlen($found->originalRequest), $found->emailRecordId, $found->status]);
        self::assertSame('2026-10-08 02:03:04.123456', $found->createdAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-11 02:03:04.123456', $found->tokenExpiresAt->format('Y-m-d H:i:s.u'));
        self::assertSame(hash('sha256', 'token-a'), $found->tokenHash);
        self::assertNull($found->submittedDate);
        self::assertNull($this->store->findCreditNote('missing'));
    }

    public function test_a_token_hash_can_be_used_once_and_a_second_use_is_a_conflict(): void
    {
        $this->store->insertCreditNote($this->note('a'));

        $this->expectException(ArConflict::class);
        $this->store->insertCreditNote($this->note('b', tokenHash: hash('sha256', 'token-a')));
    }

    public function test_the_database_refuses_a_status_that_is_not_one_of_the_seven(): void
    {
        $this->store->insertCreditNote($this->note('a'));

        try {
            $this->store->updateCreditNote($this->note('a', status: 'overdue'));
            self::fail('Expected the check constraint to refuse it.');
        } catch (Throwable $refused) {
            self::assertNotSame('', $refused->getMessage());
        }
        self::assertSame('form_sent', $this->statusOf('a'));
    }

    public function test_the_submit_is_single_use_exactly_one_caller_wins(): void
    {
        $this->store->insertCreditNote($this->note('a'));
        $at = ArFixtures::utc('2026-10-08 03:00:00.5');

        self::assertTrue($this->store->submitCreditNote('a', 'INV-9', 99.5, 'Reason', 'Rae', $at));
        self::assertFalse($this->store->submitCreditNote('a', 'LOSER', 1.0, 'Late', 'Late', $at), 'the second finds it already submitted');

        $found = $this->store->findCreditNote('a');
        self::assertSame(['submitted', 'INV-9', 99.5, 'Reason', 'Rae'], [$found?->status, $found?->invoiceNumber, $found?->creditAmount, $found?->reason, $found?->salesRepName]);
        self::assertSame('2026-10-08 03:00:00.500000', $found?->submittedDate?->format('Y-m-d H:i:s.u'));
    }

    public function test_the_submit_works_from_awaiting_and_not_from_any_closed_status(): void
    {
        $this->store->insertCreditNote($this->note('a', status: 'awaiting_sales_response'));
        foreach (['under_review', 'approved', 'rejected', 'completed', 'submitted'] as $i => $status) {
            $this->store->insertCreditNote($this->note('c' . $i, status: $status, tokenHash: hash('sha256', 'c' . $i)));
        }
        $at = ArFixtures::utc('2026-10-08 03:00:00');

        self::assertTrue($this->store->submitCreditNote('a', 'X', 1.0, '', '', $at));
        foreach (['c0', 'c1', 'c2', 'c3', 'c4'] as $id) {
            self::assertFalse($this->store->submitCreditNote($id, 'X', 1.0, '', '', $at), $id);
        }
        self::assertFalse($this->store->submitCreditNote('missing', 'X', 1.0, '', '', $at));
    }

    public function test_a_new_token_replaces_the_old_only_while_the_request_is_open(): void
    {
        $this->store->insertCreditNote($this->note('a'));
        $this->store->insertCreditNote($this->note('b', status: 'approved', tokenHash: hash('sha256', 'token-b')));
        $at = ArFixtures::utc('2026-10-09 00:00:00');

        self::assertTrue($this->store->replaceCreditNoteToken('a', hash('sha256', 'fresh'), $at->modify('+72 hours'), $at));
        self::assertFalse($this->store->replaceCreditNoteToken('b', hash('sha256', 'fresh-b'), $at->modify('+72 hours'), $at));

        $a = $this->store->findCreditNote('a');
        self::assertSame([hash('sha256', 'fresh'), '2026-10-12 00:00:00'], [$a?->tokenHash, $a?->tokenExpiresAt->format('Y-m-d H:i:s')]);
        self::assertSame($at->format('c'), $a?->formSentDate?->format('c'));
        self::assertSame(hash('sha256', 'token-b'), $this->store->findCreditNote('b')?->tokenHash, 'a closed request keeps its token');
    }

    public function test_an_update_writes_the_editable_fields_the_status_and_reviewed_at_and_never_the_token(): void
    {
        $this->store->insertCreditNote($this->note('a'));
        $changed = $this->note('a', customerName: 'Renamed', invoiceNumber: 'INV-2', creditAmount: 7.0, status: 'approved', reviewedAt: ArFixtures::utc('2026-10-09 01:00:00'), updatedAt: ArFixtures::utc('2026-10-09 01:00:00'), tokenHash: hash('sha256', 'different'));

        $this->store->updateCreditNote($changed);

        $found = $this->store->findCreditNote('a');
        self::assertSame(['Renamed', 'INV-2', 7.0, 'approved', hash('sha256', 'token-a')], [$found?->customerName, $found?->invoiceNumber, $found?->creditAmount, $found?->status, $found?->tokenHash]);
        self::assertSame('2026-10-09 01:00:00', $found?->reviewedAt?->format('Y-m-d H:i:s'));
    }

    public function test_the_list_is_newest_first_filtered_with_literal_wildcards_and_bounded(): void
    {
        $this->store->insertCreditNote($this->note('a', customerName: '100%_Real [Co]', salesRepEmail: 'a@example.test', createdAt: ArFixtures::utc('2026-10-01 00:00:00')));
        $this->store->insertCreditNote($this->note('b', customerName: 'Plain Co', salesRepEmail: 'b@example.test', status: 'approved', createdAt: ArFixtures::utc('2026-10-03 00:00:00'), tokenHash: hash('sha256', 'b')));
        $this->store->insertCreditNote($this->note('c', customerName: 'Third', salesRepEmail: 'a@example.test', createdAt: ArFixtures::utc('2026-10-02 00:00:00'), tokenHash: hash('sha256', 'c')));

        self::assertSame([['b', 'c', 'a'], 3], [$this->ids($this->store->pageCreditNotes(null, null, null, 10, 0)), $this->store->pageCreditNotes(null, null, null, 10, 0)[1]]);
        self::assertSame(['a'], $this->ids($this->store->pageCreditNotes(null, '100%_', null, 10, 0)), 'wildcards match themselves');
        self::assertSame(['a'], $this->ids($this->store->pageCreditNotes(null, '[Co]', null, 10, 0)));
        self::assertSame(['a'], $this->ids($this->store->pageCreditNotes(null, '%', null, 10, 0)), 'a lone % is a literal percent sign');
        self::assertSame(['b', 'a'], $this->ids($this->store->pageCreditNotes(null, 'co', null, 10, 0)), 'contains, without regard to case');
        self::assertSame(['b'], $this->ids($this->store->pageCreditNotes('approved', null, null, 10, 0)));
        self::assertSame(['c', 'a'], $this->ids($this->store->pageCreditNotes(null, null, 'A@Example.test', 10, 0)), 'the address matches without regard to case');
        self::assertSame([['c'], 3], [$this->ids($this->store->pageCreditNotes(null, null, null, 1, 1)), $this->store->pageCreditNotes(null, null, null, 1, 1)[1]], 'the total ignores the page');
        self::assertSame([[], 3], [$this->ids($this->store->pageCreditNotes(null, null, null, 10, 10)), $this->store->pageCreditNotes(null, null, null, 10, 10)[1]]);
    }

    public function test_the_register_keeps_a_request_id_and_the_metrics_count_requests_by_status(): void
    {
        $records = new AzureSqlArRecordStore(new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()));
        $records->insert(ArFixtures::record('r1'));
        $this->store->insertCreditNote($this->note('a', emailRecordId: 'r1'));
        $this->store->insertCreditNote($this->note('b', status: 'approved', tokenHash: hash('sha256', 'b')));
        $this->store->insertCreditNote($this->note('c', status: 'approved', tokenHash: hash('sha256', 'c')));

        self::assertSame('r1', $this->store->findCreditNote('a')?->emailRecordId);
        self::assertEquals(['form_sent' => 1, 'approved' => 2], $records->metrics(null, null, '2026-10-08')['credit_notes']);
    }
}
