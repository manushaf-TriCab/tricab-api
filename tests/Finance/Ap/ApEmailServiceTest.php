<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailArrival;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Service\ApEmailService;

/**
 * The AP Mailbox's use cases (T6.6) over the in-memory stores: the list and its period, scope and paging, the invoice links,
 * the counts, the reclassification and the three bulk actions with their audit events, and the calls the scan and the mail
 * tasks make. Every validation message is the source's.
 */
final class ApEmailServiceTest extends TestCase
{
    private ApEmailWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApEmailWorld();
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>|null $mailboxes
     * @return list<string>
     */
    private function listed(array $params = [], ?array $mailboxes = null): array
    {
        return ApInvoiceFixtures::ids($this->world->service->list(ApInvoiceFixtures::actor($mailboxes), $params));
    }

    private function refuses(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('Expected a refusal: ' . $message);
        } catch (ApInvalid $invalid) {
            self::assertSame($message, $invalid->getMessage());
            self::assertSame(422, $invalid->httpStatus());
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bulkRefuses(array $body, string $message): void
    {
        $this->refuses(fn () => $this->world->service->bulk(ApInvoiceFixtures::actor(), $body), $message);
    }

    // ---- the list ------------------------------------------------------------------------

    public function test_the_list_is_newest_first_with_the_id_breaking_ties(): void
    {
        $same = ApEmailWorld::utc('2026-10-05 01:00:00');
        $this->world->email('a', receivedAt: $same);
        $this->world->email('c', receivedAt: $same);
        $this->world->email('b', receivedAt: $same);
        $this->world->email('z', receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $this->world->email('n', receivedAt: null);

        self::assertSame(['c', 'b', 'a', 'z', 'n'], $this->listed());
    }

    public function test_paging_by_limit_and_offset_neither_repeats_nor_skips_a_row(): void
    {
        foreach (['p0', 'p1', 'p2', 'p3', 'p4'] as $id) {
            $this->world->email($id, receivedAt: ApEmailWorld::utc('2026-10-05 01:00:00'));
        }
        $all = $this->listed();
        $pages = [...$this->listed(['limit' => '2']), ...$this->listed(['limit' => '2', 'offset' => '2']), ...$this->listed(['limit' => '2', 'offset' => '4'])];

        self::assertSame($all, $pages);
        self::assertCount(5, $all);
        self::assertSame([], $this->listed(['offset' => '9']));
        self::assertSame($all, $this->listed(['offset' => '-3']), 'a negative offset is 0');
    }

    public function test_limit_is_a_whole_number_from_one_and_is_capped_at_a_thousand(): void
    {
        $this->world->email('a');
        foreach (['abc', '1.5', '0x1'] as $bad) {
            $this->refuses(fn () => $this->listed(['limit' => $bad]), 'limit must be a whole number');
        }
        $this->refuses(fn () => $this->listed(['limit' => '0']), 'limit must be at least 1');
        $this->refuses(fn () => $this->listed(['limit' => '-5']), 'limit must be at least 1');
        $this->refuses(fn () => $this->listed(['offset' => 'x']), 'offset must be a whole number');
        self::assertSame(['a'], $this->listed(['limit' => '99999']));
    }

    public function test_the_period_is_the_exact_local_days_and_one_date_alone_is_that_day(): void
    {
        // 5 Oct 2026 in Melbourne (+11) is 2026-10-04T13:00:00Z to 2026-10-05T12:59:59Z.
        $this->world->email('before', receivedAt: ApEmailWorld::utc('2026-10-04 12:59:59'));
        $this->world->email('first', receivedAt: ApEmailWorld::utc('2026-10-04 13:00:00'));
        $this->world->email('last', receivedAt: ApEmailWorld::utc('2026-10-05 12:59:59'));
        $this->world->email('lastfraction', receivedAt: ApEmailWorld::utc('2026-10-05 12:59:59.5'));
        $this->world->email('after', receivedAt: ApEmailWorld::utc('2026-10-05 13:00:00'));
        $this->world->email('unknown', receivedAt: null);

        self::assertSame(['lastfraction', 'last', 'first'], $this->listed(['fromDate' => '2026-10-05']));
        self::assertSame(['lastfraction', 'last', 'first'], $this->listed(['toDate' => '2026-10-05']));
        self::assertSame(['lastfraction', 'last', 'first'], $this->listed(['fromDate' => '2026-10-05', 'toDate' => '2026-10-05']));
        self::assertSame(['after', 'lastfraction', 'last', 'first'], $this->listed(['fromDate' => '2026-10-05', 'toDate' => '2026-10-06']));
        self::assertSame(['after', 'lastfraction', 'last', 'first', 'before', 'unknown'], $this->listed(), 'no dates is no bound');
        self::assertSame($this->listed(), $this->listed(['fromDate' => '', 'toDate' => '']));
    }

    public function test_a_bad_date_and_a_from_after_to_are_refused_in_the_sources_words(): void
    {
        foreach (['2026-13-01', 'yesterday', '2026-1-1', '20261001', '2026-02-30'] as $bad) {
            $this->refuses(fn () => $this->listed(['fromDate' => $bad]), 'Dates must use YYYY-MM-DD');
            $this->refuses(fn () => $this->listed(['toDate' => $bad]), 'Dates must use YYYY-MM-DD');
        }
        $this->refuses(fn () => $this->listed(['fromDate' => ['x']]), 'Dates must use YYYY-MM-DD');
        $this->refuses(fn () => $this->listed(['fromDate' => '2026-10-06', 'toDate' => '2026-10-05']), 'From date cannot be after to date');
        $this->refuses(fn () => $this->world->service->stats(ApInvoiceFixtures::actor(), ['fromDate' => '2026-10-06', 'toDate' => '2026-10-05']), 'From date cannot be after to date');
        $this->refuses(fn () => $this->world->service->stats(ApInvoiceFixtures::actor(), ['toDate' => 'nope']), 'Dates must use YYYY-MM-DD');
    }

    public function test_the_classification_filter_takes_invoice_or_other_and_an_empty_value_is_refused(): void
    {
        $this->world->email('i', classification: ApEmail::INVOICE);
        $this->world->email('o', classification: ApEmail::OTHER, receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));

        self::assertSame(['i'], $this->listed(['classification' => 'Invoice']));
        self::assertSame(['o'], $this->listed(['classification' => 'Other']));
        foreach (['', 'invoice', 'Error', 'x'] as $bad) {
            $this->refuses(fn () => $this->listed(['classification' => $bad]), 'classification must be Invoice or Other');
        }
    }

    public function test_the_bin_is_listed_with_deleted_and_the_live_list_leaves_it_out(): void
    {
        $this->world->email('live');
        $this->world->email('binned', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'), deletedBy: 'dee@example.test');

        self::assertSame(['live'], $this->listed());
        self::assertSame(['binned'], $this->listed(['deleted' => '1']));
        self::assertSame(['binned'], $this->listed(['deleted' => 'true']));
        self::assertSame(['live'], $this->listed(['deleted' => '0']));
        $row = $this->world->service->list(ApInvoiceFixtures::actor(), ['deleted' => '1'])[0];
        self::assertSame('dee@example.test', $row['deleted_by']);
        self::assertSame('2026-10-06T00:00:00.000000+00:00', $row['deleted_at']);
    }

    public function test_the_scope_is_applied_before_the_page_in_any_case(): void
    {
        $this->world->email('mine', mailbox: 'Ap@Example.test');
        $this->world->email('theirs', mailbox: 'other@example.test', receivedAt: ApEmailWorld::utc('2026-10-06 01:00:00'));

        self::assertSame(['theirs', 'mine'], $this->listed());
        self::assertSame(['mine'], $this->listed(['limit' => '1'], ['ap@example.test']));
        self::assertSame(['theirs'], $this->listed([], ['OTHER@example.test']));
    }

    public function test_a_row_has_every_key_the_page_reads(): void
    {
        $this->world->email('a', classificationReason: 'Looks like an invoice', classificationConfidence: 0.91, reviewStatus: ApEmail::VERIFIED, proposedResponse: 'Thanks', scanRunId: null);

        $row = $this->world->service->list(ApInvoiceFixtures::actor(), [])[0];

        foreach (['id', 'graph_message_id', 'mailbox', 'received_at', 'sender_name', 'sender_email', 'subject', 'body_preview', 'web_link', 'has_attachments', 'classification', 'classification_reason', 'classification_confidence', 'review_status', 'proposed_response', 'scan_run_id', 'deleted_at', 'deleted_by', 'created_date', 'updated_date', 'invoice_ids', 'duplicate_invoice_ids'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame('msg-a', $row['graph_message_id']);
        self::assertSame('2026-10-05T01:00:00.000000+00:00', $row['received_at']);
        self::assertSame(0.91, $row['classification_confidence']);
        self::assertNull($row['deleted_at']);
        self::assertNull($row['deleted_by']);
        self::assertSame([], $row['invoice_ids']);
        self::assertSame([], $row['duplicate_invoice_ids']);
    }

    // ---- the invoices of each email ------------------------------------------------------

    public function test_three_invoices_from_one_email_are_three_ids_in_creation_order(): void
    {
        $this->world->email('e1');
        foreach (['i3' => '2026-10-05 03:00:00', 'i1' => '2026-10-05 01:00:00', 'i2' => '2026-10-05 02:00:00'] as $id => $created) {
            $this->world->invoices->insert(ApInvoiceFixtures::invoice($id, emailRecordId: 'e1', createdAt: ApEmailWorld::utc($created)));
        }

        $row = $this->world->service->list(ApInvoiceFixtures::actor(), [])[0];

        self::assertSame(['i1', 'i2', 'i3'], $row['invoice_ids']);
        self::assertSame([], $row['duplicate_invoice_ids']);
    }

    public function test_a_resent_invoice_is_in_both_lists_and_a_potential_duplicate_stays_in_own(): void
    {
        $this->world->invoices->insert(ApInvoiceFixtures::invoice('orig'));
        $this->world->email('resent');
        $this->world->attachment('a1', 'resent', isInvoice: true, invoiceId: null, duplicateOfInvoiceId: 'orig');
        $this->world->attachment('a2', 'resent', isInvoice: true, invoiceId: null, duplicateOfInvoiceId: 'orig');
        $this->world->attachment('a3', 'resent', isInvoice: false, duplicateOfInvoiceId: 'orig');
        $this->world->email('potential', receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $this->world->invoices->insert(ApInvoiceFixtures::invoice('pot', emailRecordId: 'potential', duplicateStatus: 'Potential', duplicateOfInvoiceId: 'orig'));
        $this->world->attachment('a4', 'potential', isInvoice: true, invoiceId: 'pot', duplicateOfInvoiceId: 'orig');

        $rows = [];
        foreach ($this->world->service->list(ApInvoiceFixtures::actor(), []) as $row) {
            self::assertIsString($row['id']);
            $rows[$row['id']] = $row;
        }

        self::assertSame(['orig'], $rows['resent']['invoice_ids']);
        self::assertSame(['orig'], $rows['resent']['duplicate_invoice_ids'], 'distinct, and a non-invoice file is not counted');
        self::assertSame(['pot'], $rows['potential']['invoice_ids']);
        self::assertSame([], $rows['potential']['duplicate_invoice_ids']);
    }

    public function test_a_duplicate_already_among_the_emails_own_invoices_is_listed_once(): void
    {
        $this->world->email('e1');
        $this->world->invoices->insert(ApInvoiceFixtures::invoice('mine', emailRecordId: 'e1'));
        $this->world->attachment('a1', 'e1', isInvoice: true, invoiceId: null, duplicateOfInvoiceId: 'mine');

        $row = $this->world->service->list(ApInvoiceFixtures::actor(), [])[0];

        self::assertSame(['mine'], $row['invoice_ids']);
        self::assertSame([], $row['duplicate_invoice_ids']);
    }

    public function test_a_binned_invoice_stays_in_own_and_a_deleted_one_drops_out(): void
    {
        $this->world->email('e1');
        $this->world->invoices->insert(ApInvoiceFixtures::invoice('binned', emailRecordId: 'e1', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00')));
        $this->world->attachment('a1', 'e1', isInvoice: true, invoiceId: null, duplicateOfInvoiceId: 'gone');

        $row = $this->world->service->list(ApInvoiceFixtures::actor(), [])[0];

        self::assertSame(['binned'], $row['invoice_ids']);
        self::assertSame([], $row['duplicate_invoice_ids'], 'an invoice that no longer exists is not a link');
    }

    // ---- the counts ----------------------------------------------------------------------

    public function test_the_stats_count_live_emails_in_the_period_and_scope(): void
    {
        $this->world->email('i1', classification: ApEmail::INVOICE);
        $this->world->email('i2', classification: ApEmail::INVOICE);
        $this->world->email('o1', classification: ApEmail::OTHER);
        $this->world->email('err', classification: ApEmail::ERROR);
        $this->world->email('bin', classification: ApEmail::INVOICE, deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'));
        $this->world->email('old', classification: ApEmail::OTHER, receivedAt: ApEmailWorld::utc('2026-09-01 01:00:00'));
        $this->world->email('elsewhere', classification: ApEmail::OTHER, mailbox: 'other@example.test');
        $actor = ApInvoiceFixtures::actor();

        self::assertSame(['total' => 6, 'invoice' => 2, 'other' => 3, 'errors' => 1], $this->world->service->stats($actor, []));
        self::assertSame(['total' => 5, 'invoice' => 2, 'other' => 2, 'errors' => 1], $this->world->service->stats($actor, ['fromDate' => '2026-10-05']));
        self::assertSame(['total' => 4, 'invoice' => 2, 'other' => 1, 'errors' => 1], $this->world->service->stats(ApInvoiceFixtures::actor(['ap@example.test']), ['fromDate' => '2026-10-05']));
        self::assertSame(['total' => 0, 'invoice' => 0, 'other' => 0, 'errors' => 0], $this->world->service->stats($actor, ['fromDate' => '2020-01-01']));
    }

    // ---- reclassify ----------------------------------------------------------------------

    public function test_a_reclassification_sets_the_type_the_reason_and_verified_and_writes_the_audit_event(): void
    {
        $this->world->email('e1', classification: ApEmail::OTHER, classificationConfidence: 0.4, reviewStatus: ApEmail::NEEDS_REVIEW);
        $this->world->invoices->insert(ApInvoiceFixtures::invoice('inv', emailRecordId: 'e1'));

        $row = $this->world->service->reclassify(ApInvoiceFixtures::actor(), 'e1', ['classification' => 'Invoice', 'reason' => '  It is one  ', 'status' => 'ignored']);

        self::assertSame('Invoice', $row['classification']);
        self::assertSame('It is one', $row['classification_reason']);
        self::assertSame('Verified', $row['review_status']);
        self::assertSame(0.4, $row['classification_confidence'], 'the confidence is untouched');
        self::assertArrayNotHasKey('invoice_ids', $row);
        self::assertSame('2026-10-09T01:02:03.456789+00:00', $row['updated_date']);
        $events = $this->world->audit->forEntity(ApAuditEvent::ENTITY_EMAIL, 'e1');
        self::assertCount(1, $events);
        self::assertSame('email_reclassified', $events[0]->action);
        self::assertSame(['from' => 'Other', 'to' => 'Invoice', 'reason' => 'It is one'], $events[0]->details);
        self::assertSame(7, $events[0]->actorUserId);
        self::assertNotNull($this->world->invoices->find('inv'), 'the linked invoice is untouched');
    }

    public function test_no_reason_reads_manually_classified_and_audits_a_null_reason(): void
    {
        $this->world->email('e1');
        $this->world->email('e2', receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));

        $a = $this->world->service->reclassify(ApInvoiceFixtures::actor(), 'e1', ['classification' => 'Other']);
        $b = $this->world->service->reclassify(ApInvoiceFixtures::actor(), 'e2', ['classification' => 'Other', 'reason' => '   ']);

        self::assertSame('Manually classified', $a['classification_reason']);
        self::assertSame('Manually classified', $b['classification_reason']);
        self::assertNull($this->world->audit->forEntity('email', 'e1')[0]->details['reason']);
    }

    public function test_a_binned_email_can_be_reclassified_and_stays_binned(): void
    {
        $this->world->email('e1', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'), deletedBy: 'x@example.test');

        $row = $this->world->service->reclassify(ApInvoiceFixtures::actor(), 'e1', ['classification' => 'Invoice']);

        self::assertNotNull($row['deleted_at']);
    }

    public function test_reclassify_refuses_a_bad_classification_a_bad_reason_and_an_unknown_or_out_of_scope_email(): void
    {
        $this->world->email('e1');
        $actor = ApInvoiceFixtures::actor();
        foreach ([null, '', 'Error', 'invoice', 5] as $bad) {
            $this->refuses(fn () => $this->world->service->reclassify($actor, 'e1', ['classification' => $bad]), 'classification must be Invoice or Other');
        }
        $this->refuses(fn () => $this->world->service->reclassify($actor, 'e1', ['classification' => 'Other', 'reason' => 5]), 'reason must be text');
        $this->refuses(fn () => $this->world->service->reclassify($actor, 'e1', ['classification' => 'Other', 'reason' => str_repeat('x', 1001)]), 'reason must be at most 1000 characters');
        foreach ([[$actor, 'missing'], [ApInvoiceFixtures::actor(['other@example.test']), 'e1']] as [$who, $id]) {
            try {
                $this->world->service->reclassify($who, $id, ['classification' => 'Other']);
                self::fail('Expected Not found.');
            } catch (ApNotFound $notFound) {
                self::assertSame('Not found', $notFound->getMessage());
            }
        }
        self::assertSame([], $this->world->audit->all());
    }

    // ---- bulk ----------------------------------------------------------------------------

    public function test_bulk_validates_in_the_sources_order(): void
    {
        $actor = ApInvoiceFixtures::actor();
        foreach ([[], ['action' => 'delete'], ['action' => 5], ['action' => null, 'ids' => ['a']]] as $body) {
            $this->bulkRefuses($body, 'action must be classify, bin or restore');
        }
        foreach ([null, 'a', [], ['a', 5], [['a']], ['x' => 'a']] as $ids) {
            $this->bulkRefuses(['action' => 'bin', 'ids' => $ids], 'ids must be a non-empty list of email ids');
        }
        $this->bulkRefuses(['action' => 'bin', 'ids' => array_map(strval(...), range(1, 1001))], 'At most 1000 emails at a time');
        $this->bulkRefuses(['action' => 'classify', 'ids' => ['a'], 'classification' => 'Error'], 'classification must be Invoice or Other');
        $this->bulkRefuses(['action' => 'classify', 'ids' => ['a']], 'classification must be Invoice or Other');
        self::assertSame(['updated' => 0, 'skipped' => 1, 'emails' => []], $this->world->service->bulk($actor, ['action' => 'bin', 'ids' => ['a']]));
    }

    public function test_the_thousand_limit_is_counted_before_de_duplication(): void
    {
        $actor = ApInvoiceFixtures::actor();
        $this->refuses(fn () => $this->world->service->bulk($actor, ['action' => 'bin', 'ids' => array_fill(0, 1001, 'same')]), 'At most 1000 emails at a time');
        self::assertSame(1, $this->world->service->bulk($actor, ['action' => 'bin', 'ids' => array_fill(0, 1000, 'same')])['skipped']);
    }

    public function test_bin_moves_emails_to_the_bin_audits_each_and_leaves_invoices_alone(): void
    {
        $this->world->email('e1', subject: 'Statement');
        $this->world->email('e2', receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $this->world->invoices->insert(ApInvoiceFixtures::invoice('inv', emailRecordId: 'e1'));

        $out = $this->world->service->bulk(ApInvoiceFixtures::actor(), ['action' => 'bin', 'ids' => ['e1', 'e2', 'e1', 'nope']]);

        self::assertSame(2, $out['updated']);
        self::assertSame(1, $out['skipped']);
        self::assertSame(['e1', 'e2'], array_column($out['emails'], 'id'));
        self::assertSame('dee@example.test', $out['emails'][0]['deleted_by'], 'the address, in lower case');
        self::assertSame('2026-10-09T01:02:03.456789+00:00', $out['emails'][0]['deleted_at']);
        self::assertArrayNotHasKey('invoice_ids', $out['emails'][0]);
        $events = $this->world->audit->forEntity('email', 'e1');
        self::assertCount(1, $events, 'a repeated id is processed once');
        self::assertSame('email_binned', $events[0]->action);
        self::assertSame(['subject' => 'Statement'], $events[0]->details);
        $invoice = $this->world->invoices->find('inv');
        self::assertNotNull($invoice);
        self::assertFalse($invoice->isBinned());
        self::assertSame([], $this->listed());
        self::assertSame(['e1', 'e2'], $this->listed(['deleted' => '1']));
    }

    public function test_restore_clears_the_bin_and_an_email_in_the_target_state_is_counted_without_change_or_audit(): void
    {
        $this->world->email('binned', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'), deletedBy: 'x@example.test');
        $this->world->email('live', receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));
        $actor = ApInvoiceFixtures::actor();

        $out = $this->world->service->bulk($actor, ['action' => 'restore', 'ids' => ['binned', 'live']]);

        self::assertSame(2, $out['updated']);
        self::assertNull($out['emails'][0]['deleted_at']);
        self::assertNull($out['emails'][0]['deleted_by']);
        self::assertSame(['email_restored'], array_map(static fn (ApAuditEvent $e): string => $e->action, $this->world->audit->all()));

        $again = $this->world->service->bulk($actor, ['action' => 'bin', 'ids' => ['binned', 'live']]);
        $beforeAudit = count($this->world->audit->all());
        $already = $this->world->service->bulk($actor, ['action' => 'bin', 'ids' => ['binned', 'live']]);

        self::assertSame(2, $again['updated']);
        self::assertSame(2, $already['updated']);
        self::assertCount($beforeAudit, $this->world->audit->all(), 'binning what is binned writes nothing');
        self::assertSame($again['emails'][0]['deleted_at'], $already['emails'][0]['deleted_at'], 'and changes nothing');
    }

    public function test_classify_always_applies_and_audits_with_bulk_true(): void
    {
        $this->world->email('e1', classification: ApEmail::INVOICE);
        $this->world->email('e2', classification: ApEmail::OTHER, receivedAt: ApEmailWorld::utc('2026-10-04 01:00:00'));

        $out = $this->world->service->bulk(ApInvoiceFixtures::actor(), ['action' => 'classify', 'ids' => ['e1', 'e2'], 'classification' => 'Other', 'reason' => 'Statements']);

        self::assertSame(2, $out['updated']);
        foreach ($out['emails'] as $row) {
            self::assertSame('Other', $row['classification']);
            self::assertSame('Statements', $row['classification_reason']);
            self::assertSame('Verified', $row['review_status']);
        }
        $events = $this->world->audit->all();
        self::assertSame(['from' => 'Invoice', 'to' => 'Other', 'bulk' => true], $events[0]->details);
        self::assertSame(['from' => 'Other', 'to' => 'Other', 'bulk' => true], $events[1]->details);
    }

    public function test_bulk_skips_what_is_out_of_scope_and_counts_it(): void
    {
        $this->world->email('mine');
        $this->world->email('theirs', mailbox: 'other@example.test');

        $out = $this->world->service->bulk(ApInvoiceFixtures::actor(['ap@example.test']), ['action' => 'bin', 'ids' => ['mine', 'theirs']]);

        self::assertSame(1, $out['updated']);
        self::assertSame(1, $out['skipped']);
        self::assertFalse($this->world->emails->find('theirs')?->isBinned());
        self::assertCount(1, $this->world->audit->all());
    }

    // ---- for the scan and the mail tasks -------------------------------------------------

    private function arrival(string $subject = 'Hello', ?string $run = 'run-1', string $mailbox = 'AP@Example.test', string $graphId = 'imm-1'): ApEmailArrival
    {
        return new ApEmailArrival($mailbox, $graphId, ApEmailWorld::utc('2026-10-05 01:00:00'), 'Vera', 'vera@vendor.example.test', $subject, 'Preview', 'https://outlook.example.test/x', true, $run);
    }

    public function test_register_stores_a_new_message_as_other_pending_unverified_and_finds_it_in_any_case(): void
    {
        $email = $this->world->service->register($this->arrival());

        self::assertSame('ap@example.test', $email->mailbox);
        self::assertSame('Other', $email->classification);
        self::assertSame('Pending classification', $email->classificationReason);
        self::assertSame('Unverified', $email->reviewStatus);
        self::assertSame('run-1', $email->scanRunId);
        self::assertSame(32, strlen($email->id));
        self::assertSame($email->id, $this->world->service->findByMessage(' AP@example.test ', 'IMM-1')?->id);
        self::assertNull($this->world->service->findByMessage('other@example.test', 'imm-1'));
    }

    public function test_register_again_refreshes_the_facts_and_keeps_what_the_scan_and_the_people_decided(): void
    {
        $first = $this->world->service->register($this->arrival());
        $this->world->service->applyScanResult($first->id, ['classification' => 'Invoice', 'classification_reason' => 'Has a PDF', 'classification_confidence' => 0.9, 'review_status' => 'Verified', 'proposed_response' => 'Thanks']);
        $this->world->emails->bin($first->id, 7, 'dee@example.test', ApEmailWorld::utc('2026-10-06 00:00:00'));

        $again = $this->world->service->register($this->arrival('Changed subject', 'run-2'));

        self::assertSame($first->id, $again->id);
        self::assertSame('Changed subject', $again->subject);
        self::assertSame('run-2', $again->scanRunId);
        self::assertSame('Invoice', $again->classification);
        self::assertSame('Has a PDF', $again->classificationReason);
        self::assertSame('Verified', $again->reviewStatus);
        self::assertSame('Thanks', $again->proposedResponse);
        self::assertTrue($again->isBinned());
        self::assertCount(1, $this->world->emails->page(new \TriCab\Core\Finance\Ap\Domain\ApEmailQuery(true, null, null, null, null)));
    }

    public function test_register_cuts_long_text_to_its_column(): void
    {
        $email = $this->world->service->register($this->arrival(str_repeat('s', 500)));

        self::assertSame(400, mb_strlen($email->subject));
    }

    public function test_apply_scan_result_checks_what_it_writes(): void
    {
        $email = $this->world->service->register($this->arrival());

        $done = $this->world->service->applyScanResult($email->id, ['classification' => 'Invoice', 'classification_reason' => str_repeat('r', 1500), 'classification_confidence' => 1.7, 'review_status' => 'Needs review']);

        self::assertSame(1000, mb_strlen((string) $done->classificationReason));
        self::assertSame(1.0, $done->classificationConfidence);
        self::assertSame('Needs review', $done->reviewStatus);
        $this->refuses(fn () => $this->world->service->applyScanResult($email->id, ['classification' => 'Maybe']), 'classification must be Invoice or Other');
        $this->refuses(fn () => $this->world->service->applyScanResult($email->id, ['review_status' => 'Done']), 'Unknown review status');
        $this->refuses(fn () => $this->world->service->applyScanResult($email->id, ['mailbox' => 'x']), 'Not a scan column: mailbox');
    }

    public function test_attachments_are_recorded_once_per_graph_id_and_linked_to_invoices(): void
    {
        $email = $this->world->service->register($this->arrival());

        $first = $this->world->service->recordAttachment($email->id, 'att-1', 'bill.pdf', 'application/pdf', 1234, str_repeat('a', 64));
        $again = $this->world->service->recordAttachment($email->id, 'ATT-1', 'bill-v2.pdf', 'application/pdf', 1300, null, null, true);
        $noId = $this->world->service->recordAttachment($email->id, null, 'a.png', 'image/png', null);
        $noId2 = $this->world->service->recordAttachment($email->id, '', 'a.png', 'image/png', null);

        self::assertSame($first->id, $again->id);
        self::assertNotSame($noId->id, $noId2->id, 'a file with no Graph id is always a new row');
        self::assertCount(3, $this->world->service->attachmentsOf($email->id));
        self::assertSame('bill-v2.pdf', $this->world->emails->findAttachment($email->id, 'att-1')?->name);

        $this->world->invoices->insert(ApInvoiceFixtures::invoice('orig'));
        $this->world->service->linkAttachment($first->id, true, null, 'orig');
        $row = $this->world->service->list(ApInvoiceFixtures::actor(), [])[0];
        self::assertSame(['orig'], $row['duplicate_invoice_ids']);
        self::assertSame(['orig'], $row['invoice_ids']);

        $this->world->invoices->insert(ApInvoiceFixtures::invoice('new', emailRecordId: $email->id));
        $this->world->service->linkAttachment($first->id, true, 'new', null);
        $row = $this->world->service->list(ApInvoiceFixtures::actor(), [])[0];
        self::assertSame(['new'], $row['invoice_ids']);
        self::assertSame([], $row['duplicate_invoice_ids']);
    }

    public function test_get_is_the_mail_tasks_way_to_a_reply_and_its_proposed_response(): void
    {
        $email = $this->world->service->register($this->arrival());
        $this->world->service->applyScanResult($email->id, ['proposed_response' => 'Dear Vera']);

        self::assertSame('Dear Vera', $this->world->service->get(ApInvoiceFixtures::actor(), $email->id)->proposedResponse);
        $this->expectException(ApNotFound::class);
        $this->world->service->get(ApInvoiceFixtures::actor(['other@example.test']), $email->id);
    }
}
