<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ap\AzureSqlApAuditStore;
use TriCab\Api\Finance\Ap\AzureSqlApEmailStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailArrival;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Domain\ApEmailQuery;
use TriCab\Core\Finance\Ap\Service\ApEmailService;
use TriCab\Core\Finance\Ap\Service\ApTime;

/**
 * The AP Mailbox over `create-finance-invoice-schema.sql`, against a real local SQL Server ({@see LocalFinanceSqlServer});
 * skipped without one: the types the store reads and writes, the period, scope and class before the page, the stable order,
 * the unique (mailbox, message) key, the Bin, the attachments and their links, and the page's invoice links in a fixed
 * number of statements however many rows it holds (D75).
 */
#[Group('local-sql-server')]
final class AzureSqlApEmailStoreTest extends TestCase
{
    private CountingSqlConnection $connection;

    private AzureSqlApEmailStore $store;

    private AzureSqlApInvoiceStore $invoices;

    private AzureSqlApAuditStore $audit;

    private ApEmailService $service;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new CountingSqlConnection(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
        $this->store = new AzureSqlApEmailStore($this->connection);
        $this->invoices = new AzureSqlApInvoiceStore($this->connection);
        $this->audit = new AzureSqlApAuditStore($this->connection);
        $this->service = new ApEmailService($this->store, $this->invoices, $this->audit, new ApTime(FixedClock::utc('2026-10-09 01:02:03.456789'), 'Australia/Melbourne'));
    }

    private function email(string $id, mixed ...$over): ApEmail
    {
        $email = new ApEmail(
            $id,
            'msg-' . $id,
            'ap@example.test',
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            receivedAt: ApEmailWorld::utc('2026-10-05 01:00:00'),
            senderName: 'Vera Vendor',
            senderEmail: 'billing@vendor.example.test',
            subject: 'Invoice ' . $id,
            bodyPreview: 'Preview',
            hasAttachments: true,
        );
        $email = $over === [] ? $email : $email->with(...$over);
        $this->store->insert($email);

        return $email;
    }

    private static function id(int $n): string
    {
        return str_pad(dechex($n), 32, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    private function ids(ApEmailQuery $query): array
    {
        return array_map(static fn (ApEmail $e): string => $e->id, $this->store->page($query));
    }

    public function test_an_email_round_trips_with_its_types(): void
    {
        $sent = $this->email(self::id(1), classification: ApEmail::INVOICE, classificationReason: 'Has a PDF', classificationConfidence: 0.91234567, reviewStatus: ApEmail::NEEDS_REVIEW, proposedResponse: "Dear Vera,\nThanks", scanRunId: null, webLink: 'https://outlook.example.test/x');

        $read = $this->store->find(self::id(1));

        self::assertNotNull($read);
        self::assertEquals($sent->with(updatedAt: $read->updatedAt, createdAt: $read->createdAt), $read->with(updatedAt: $read->updatedAt, createdAt: $read->createdAt));
        self::assertSame(0.91234567, $read->classificationConfidence);
        self::assertTrue($read->hasAttachments);
        self::assertSame('2026-10-05 01:00:00', $read->receivedAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $read->createdAt->getTimezone()->getName());
        self::assertNull($this->store->find(self::id(99)));
    }

    public function test_the_unique_key_is_the_mailbox_and_message_in_any_case(): void
    {
        $this->email(self::id(1), graphMessageId: 'AbC', mailbox: 'ap@example.test');

        self::assertSame(self::id(1), $this->store->findByMessage(' AP@Example.test', 'abc')?->id);
        self::assertNull($this->store->findByMessage('other@example.test', 'abc'));
        $this->email(self::id(2), graphMessageId: 'AbC', mailbox: 'other@example.test');
        $this->expectException(ApConflict::class);
        $this->email(self::id(3), graphMessageId: 'ABC', mailbox: 'ap@example.test');
    }

    public function test_the_page_is_received_at_then_id_descending_with_the_unknown_last(): void
    {
        $same = ApEmailWorld::utc('2026-10-05 01:00:00');
        $this->email(self::id(1), receivedAt: $same);
        $this->email(self::id(3), receivedAt: $same);
        $this->email(self::id(2), receivedAt: $same);
        $this->email(self::id(4), receivedAt: ApEmailWorld::utc('2026-10-06 01:00:00'));
        $this->email(self::id(5), receivedAt: null);

        $all = $this->ids(new ApEmailQuery(false, null, null, null, null));

        self::assertSame([self::id(4), self::id(3), self::id(2), self::id(1), self::id(5)], $all);
        $paged = [...$this->ids(new ApEmailQuery(false, null, null, null, null, 2, 0)), ...$this->ids(new ApEmailQuery(false, null, null, null, null, 2, 2)), ...$this->ids(new ApEmailQuery(false, null, null, null, null, 2, 4))];
        self::assertSame($all, $paged);
    }

    public function test_the_period_is_start_inclusive_end_exclusive_and_drops_an_email_with_no_time(): void
    {
        $this->email(self::id(1), receivedAt: ApEmailWorld::utc('2026-10-04 12:59:59'));
        $this->email(self::id(2), receivedAt: ApEmailWorld::utc('2026-10-04 13:00:00'));
        $this->email(self::id(3), receivedAt: ApEmailWorld::utc('2026-10-05 12:59:59.999999'));
        $this->email(self::id(4), receivedAt: ApEmailWorld::utc('2026-10-05 13:00:00'));
        $this->email(self::id(5), receivedAt: null);
        $start = ApEmailWorld::utc('2026-10-04 13:00:00');
        $end = ApEmailWorld::utc('2026-10-05 13:00:00');

        self::assertSame([self::id(3), self::id(2)], $this->ids(new ApEmailQuery(false, null, $start, $end, null)));
        self::assertSame([self::id(3), self::id(2), self::id(1)], $this->ids(new ApEmailQuery(false, null, null, $end, null)));
        self::assertSame(['total' => 2, 'invoice' => 0, 'other' => 2, 'errors' => 0], $this->store->counts($start, $end, null));
    }

    public function test_the_class_the_bin_and_the_scope_are_applied_before_the_page(): void
    {
        $this->email(self::id(1), classification: ApEmail::INVOICE);
        $this->email(self::id(2), classification: ApEmail::OTHER, receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $this->email(self::id(3), mailbox: 'Other@Example.test', receivedAt: ApEmailWorld::utc('2026-10-06 01:00:00'));
        $this->email(self::id(4), deletedAt: ApEmailWorld::utc('2026-10-07 00:00:00'), deletedByUserId: 7, deletedBy: 'dee@example.test');

        self::assertSame([self::id(1)], $this->ids(new ApEmailQuery(false, 'Invoice', null, null, null)));
        self::assertSame([self::id(4)], $this->ids(new ApEmailQuery(true, null, null, null, null)));
        self::assertSame([self::id(1), self::id(2)], $this->ids(new ApEmailQuery(false, null, null, null, ['ap@example.test'])));
        self::assertSame([self::id(3)], $this->ids(new ApEmailQuery(false, null, null, null, ['other@example.test'])));
        self::assertSame([], $this->ids(new ApEmailQuery(false, null, null, null, [])));
        $binned = $this->store->find(self::id(4));
        self::assertSame([7, 'dee@example.test'], [$binned?->deletedByUserId, $binned?->deletedBy]);
    }

    public function test_counts_by_classification_ignore_the_bin_and_are_zero_when_empty(): void
    {
        self::assertSame(['total' => 0, 'invoice' => 0, 'other' => 0, 'errors' => 0], $this->store->counts(null, null, null));
        $this->email(self::id(1), classification: ApEmail::INVOICE);
        $this->email(self::id(2), classification: ApEmail::INVOICE);
        $this->email(self::id(3), classification: ApEmail::OTHER);
        $this->email(self::id(4), classification: ApEmail::ERROR);
        $this->email(self::id(5), classification: ApEmail::INVOICE, deletedAt: ApEmailWorld::utc('2026-10-07 00:00:00'));

        self::assertSame(['total' => 4, 'invoice' => 2, 'other' => 1, 'errors' => 1], $this->store->counts(null, null, null));
        self::assertSame(['total' => 0, 'invoice' => 0, 'other' => 0, 'errors' => 0], $this->store->counts(null, null, []));
    }

    public function test_update_bin_and_restore_write_the_named_columns_only(): void
    {
        $this->email(self::id(1));
        $at = ApEmailWorld::utc('2026-10-09 01:02:03.456789');

        $this->store->update(self::id(1), ['classification' => 'Invoice', 'classification_reason' => 'Because', 'classification_confidence' => 0.5, 'review_status' => 'Verified', 'proposed_response' => 'Reply', 'scan_run_id' => null, 'has_attachments' => false, 'sender_name' => null], $at);
        $this->store->bin(self::id(1), 7, 'dee@example.test', $at);
        $this->store->bin(self::id(1), 8, 'someone.else@example.test', $at);
        $read = $this->store->find(self::id(1));

        self::assertNotNull($read);
        self::assertSame(['Invoice', 'Because', 0.5, 'Verified', 'Reply', false, ''], [$read->classification, $read->classificationReason, $read->classificationConfidence, $read->reviewStatus, $read->proposedResponse, $read->hasAttachments, $read->senderName]);
        self::assertSame([7, 'dee@example.test'], [$read->deletedByUserId, $read->deletedBy], 'binning what is binned changes nothing');
        self::assertSame('2026-10-09 01:02:03.456789', $read->updatedAt->format('Y-m-d H:i:s.u'));

        $this->store->restore(self::id(1), $at);
        $restored = $this->store->find(self::id(1));
        self::assertSame([null, null, ''], [$restored?->deletedAt, $restored?->deletedByUserId, $restored?->deletedBy]);

        $this->expectException(LogicException::class);
        $this->store->update(self::id(1), ['mailbox' => 'x'], $at);
    }

    public function test_find_many_returns_the_emails_that_exist_keyed_by_id(): void
    {
        $this->email(self::id(1));
        $this->email(self::id(2));

        $found = $this->store->findMany([self::id(2), self::id(9), self::id(1), self::id(1)]);

        self::assertEqualsCanonicalizing([self::id(1), self::id(2)], array_keys($found));
    }

    public function test_attachments_are_unique_per_graph_id_and_carry_their_links(): void
    {
        $this->email(self::id(1));
        $this->email(self::id(2), receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $now = ApEmailWorld::utc('2026-10-05 02:00:00');
        $this->store->saveAttachment(new ApEmailAttachment(self::id(11), self::id(1), $now, $now, 'AT-1', 'bill.pdf', 'application/pdf', 1234, str_repeat('a', 64)));
        $this->store->saveAttachment(new ApEmailAttachment(self::id(12), self::id(1), $now->modify('+1 second'), $now, null, 'a.png', 'image/png', null));
        $this->store->saveAttachment(new ApEmailAttachment(self::id(13), self::id(1), $now->modify('+2 seconds'), $now, null, 'a.png', 'image/png', null));
        $this->store->saveAttachment(new ApEmailAttachment(self::id(14), self::id(2), $now, $now, 'AT-1', 'same id other email.pdf', 'application/pdf', 1));

        self::assertSame(self::id(11), $this->store->findAttachment(self::id(1), 'at-1')?->id);
        self::assertNull($this->store->findAttachment(self::id(1), null));
        self::assertNull($this->store->findAttachment(self::id(1), 'missing'));
        self::assertSame([self::id(11), self::id(12), self::id(13)], array_map(static fn (ApEmailAttachment $a): string => $a->id, $this->store->attachmentsOf(self::id(1))));
        $first = $this->store->findAttachment(self::id(1), 'AT-1');
        self::assertSame([1234, str_repeat('a', 64), false, null], [$first?->size, $first?->contentHash, $first?->isInvoice, $first?->invoiceId]);
        try {
            $this->store->saveAttachment(new ApEmailAttachment(self::id(15), self::id(1), $now, $now, 'at-1'));
            self::fail('Expected a conflict.');
        } catch (ApConflict) {
            self::addToAssertionCount(1);
        }

        $this->store->updateAttachment(self::id(11), ['is_invoice' => true, 'name' => 'renamed.pdf', 'size' => null], $now->modify('+1 hour'));
        $changed = $this->store->findAttachment(self::id(1), 'AT-1');
        self::assertSame([true, 'renamed.pdf', null], [$changed?->isInvoice, $changed?->name, $changed?->size]);
        $this->expectException(LogicException::class);
        $this->store->updateAttachment(self::id(11), ['email_record_id' => self::id(2)], $now);
    }

    public function test_the_service_records_attachments_and_links_invoices_on_the_real_tables(): void
    {
        $email = $this->service->register(new ApEmailArrival('AP@example.test', 'imm-1', ApEmailWorld::utc('2026-10-05 01:00:00'), 'Vera', 'v@vendor.example.test', 'Hello', 'Preview', '', true, null));
        $again = $this->service->register(new ApEmailArrival('ap@example.test', 'IMM-1', ApEmailWorld::utc('2026-10-05 01:00:00'), 'Vera', 'v@vendor.example.test', 'Hello again', 'Preview', '', true, null));
        self::assertSame($email->id, $again->id);
        self::assertSame('Hello again', $again->subject);
        self::assertSame('Pending classification', $again->classificationReason);

        $row = $this->service->recordAttachment($email->id, 'att-1', 'bill.pdf', 'application/pdf', 99);
        $this->service->recordAttachment($email->id, 'ATT-1', 'bill.pdf', 'application/pdf', 99, null, null, true);
        self::assertCount(1, $this->service->attachmentsOf($email->id));
        $this->service->applyScanResult($email->id, ['classification' => 'Invoice', 'classification_confidence' => 0.8, 'review_status' => 'Unverified']);

        $original = $this->makeInvoice('orig', null);
        $this->service->linkAttachment($row->id, true, null, $original);
        self::assertSame([$original], $this->service->list(ApInvoiceFixtures::actor(), [])[0]['duplicate_invoice_ids']);
    }

    /** Inserts a bare invoice (optionally from an email) straight into the register and returns its id. */
    private function makeInvoice(string $name, ?string $emailRecordId, string $created = '2026-10-05 02:00:00'): string
    {
        $id = md5($name);
        $this->invoices->insert(ApInvoiceFixtures::invoice($id, emailRecordId: $emailRecordId, createdAt: ApEmailWorld::utc($created), emailId: null, mailbox: null, emailDate: null));

        return $id;
    }

    public function test_the_pages_invoice_links_cost_a_fixed_number_of_statements(): void
    {
        $original = $this->makeInvoice('orig', null);
        $count = static fn (int $rows): int => $rows;
        foreach (range(1, 40) as $n) {
            $id = self::id($n);
            $this->email($id, receivedAt: ApEmailWorld::utc('2026-10-05 01:00:00')->modify('+' . $n . ' seconds'));
            $this->makeInvoice('i' . $n, $id);
            $now = ApEmailWorld::utc('2026-10-05 02:00:00');
            $this->store->saveAttachment(new ApEmailAttachment(self::id(1000 + $n), $id, $now, $now, 'att', 'x.pdf', 'application/pdf', 1, null, null, true, null, $original));
        }
        $actor = ApInvoiceFixtures::actor();

        $this->connection->statements = 0;
        $small = $this->service->list($actor, ['limit' => '3']);
        $smallCount = $this->connection->statements;
        $this->connection->statements = 0;
        $large = $this->service->list($actor, ['limit' => '40']);
        $largeCount = $this->connection->statements;

        self::assertCount(3, $small);
        self::assertCount(40, $large);
        self::assertSame($smallCount, $largeCount, 'the statements do not grow with the rows');
        self::assertSame(3, $largeCount, 'one page, one for the invoices, one for the duplicates');
        self::assertSame([md5('i40'), $original], $large[0]['invoice_ids']);
        self::assertSame([$original], $large[0]['duplicate_invoice_ids']);
        self::assertSame(1, $count(1));
    }

    public function test_a_permanently_deleted_invoice_drops_out_of_the_duplicate_links_and_a_binned_one_stays(): void
    {
        $this->email(self::id(1));
        $keep = $this->makeInvoice('keep', null);
        $now = ApEmailWorld::utc('2026-10-05 02:00:00');
        $this->store->saveAttachment(new ApEmailAttachment(self::id(11), self::id(1), $now, $now, 'a', 'x.pdf', 'application/pdf', 1, null, null, true, null, $keep));
        $this->invoices->bin($keep, 7, 'dee@example.test', $now);

        self::assertSame([self::id(1) => [$keep]], $this->store->duplicateInvoiceIdsByEmail([self::id(1)]));

        $this->invoices->purge($keep, null, null, $now);

        self::assertSame([], $this->store->duplicateInvoiceIdsByEmail([self::id(1)]));
        self::assertSame(self::id(11), $this->store->attachmentsOf(self::id(1))[0]->id);
        self::assertNull($this->store->attachmentsOf(self::id(1))[0]->duplicateOfInvoiceId, 'purge clears the link');
    }

    public function test_bulk_and_reclassify_commit_together_with_their_audit_events(): void
    {
        $this->email(self::id(1), subject: 'Statement');
        $this->email(self::id(2), receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $actor = ApInvoiceFixtures::actor();

        $out = $this->service->bulk($actor, ['action' => 'bin', 'ids' => [self::id(1), self::id(2), self::id(2), self::id(9)]]);
        $this->service->reclassify($actor, self::id(1), ['classification' => 'Invoice', 'reason' => 'Because']);
        $this->service->bulk($actor, ['action' => 'restore', 'ids' => [self::id(1)]]);

        self::assertSame([2, 1], [$out['updated'], $out['skipped']]);
        $events = $this->audit->forEntity('email', self::id(1));
        $byAction = [];
        foreach ($events as $event) {
            $byAction[$event->action] = $event;
        }
        ksort($byAction);
        // The clock is fixed, so the three events share a time and their order is the database's; compare by action.
        self::assertSame(['email_binned', 'email_reclassified', 'email_restored'], array_keys($byAction));
        self::assertSame(['subject' => 'Statement'], $byAction['email_binned']->details);
        self::assertSame(['from' => 'Other', 'to' => 'Invoice', 'reason' => 'Because'], $byAction['email_reclassified']->details);
        self::assertSame(7, $byAction['email_binned']->actorUserId);
    }

    public function test_a_failing_bulk_rolls_back_every_change_and_event(): void
    {
        $this->email(self::id(1));
        $this->email(self::id(2), receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $failing = new class ($this->audit) implements \TriCab\Core\Finance\Ap\Contract\ApAuditStore {
            private int $calls = 0;

            public function __construct(private readonly AzureSqlApAuditStore $inner)
            {
            }

            public function record(\TriCab\Core\Finance\Ap\Domain\ApAuditEvent $event): void
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('audit down');
                }
                $this->inner->record($event);
            }

            public function forEntity(string $entityType, string $entityId): array
            {
                return $this->inner->forEntity($entityType, $entityId);
            }
        };
        $service = new ApEmailService($this->store, $this->invoices, $failing, new ApTime(FixedClock::utc('2026-10-09 01:02:03'), 'Australia/Melbourne'));

        try {
            $service->bulk(ApInvoiceFixtures::actor(), ['action' => 'bin', 'ids' => [self::id(1), self::id(2)]]);
            self::fail('Expected the failure.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertFalse($this->store->find(self::id(1))?->isBinned(), 'the first email is not left binned');
        self::assertSame([], $this->audit->forEntity('email', self::id(1)));
    }
}
