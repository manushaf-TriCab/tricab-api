<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\ApExtraction;
use TriCab\Core\Finance\Ap\Domain\ApAlreadyScanned;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApScanRun;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * Tri-AP's scan (T6.7) on the in-memory twins: the three modes, every folder of every granted mailbox, the claim and its expiry,
 * the "already scanned" 409, the per-message pipeline rule by rule (selection, exact duplicate before the model, tombstone,
 * already captured, business duplicate, grading, failures, a Verified email, the reply suggestion, forced capture) and a message
 * processed twice. Nothing reaches Graph or a model, and every address is invented.
 */
final class ApScanServiceTest extends TestCase
{
    private const PDF = "%PDF-1.4 one";

    /**
     * @param list<string> $internalDomains
     */
    private function world(bool $suggest = true, int $maxScanMessages = 0, int $listCap = 500, array $internalDomains = []): ApScanWorld
    {
        return new ApScanWorld($suggest, $maxScanMessages, $listCap, $internalDomains);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function rows(array $row, string $key): array
    {
        $list = $row[$key];
        self::assertIsArray($list);
        $out = [];
        foreach ($list as $item) {
            self::assertIsArray($item);
            $typed = [];
            foreach ($item as $k => $v) {
                $typed[(string) $k] = $v;
            }
            $out[] = $typed;
        }

        return $out;
    }

    private static function runOf(ApScanWorld $w, string $id): ApScanRun
    {
        return $w->scans->find($id) ?? self::fail('No run ' . $id);
    }

    /**
     * @param array<string, mixed> $listed a `list` answer
     */
    private static function runId(array $listed): string
    {
        $id = $listed['scanRunId'];
        self::assertIsString($id);

        return $id;
    }

    // ==================================================================================
    // list
    // ==================================================================================

    public function test_list_reads_the_whole_mailbox_in_the_app_time_zone_and_answers_the_pages_shape(): void
    {
        $w = $this->world();
        $w->message('m-new', [ApScanWorld::pdf('a1', 'a.pdf', 'x')], received: '2026-08-07 05:00:00', subject: 'New');
        $w->message('m-old', [], received: '2026-08-06 15:00:00', subject: 'Old', from: 'Old@Vendor.example.test');
        $w->message('m-sent', [], received: '2026-08-07 02:00:00', subject: 'Sent copy', folder: 'sentitems');
        $w->message('m-before', [], received: '2026-08-06 13:59:59');
        $w->message('m-after', [], received: '2026-08-07 14:00:00');

        $answer = $w->list(['fromDate' => '2026-08-07', 'toDate' => '2026-08-07', 'mailboxes' => ['ap@example.test']]);

        self::assertSame([
            ['id' => 'm-new', 'subject' => 'New', 'from' => 'billing@vendor.example.test', 'receivedDateTime' => '2026-08-07T05:00:00Z', 'mailbox' => 'ap@example.test', 'hasAttachments' => true],
            ['id' => 'm-sent', 'subject' => 'Sent copy', 'from' => 'billing@vendor.example.test', 'receivedDateTime' => '2026-08-07T02:00:00Z', 'mailbox' => 'ap@example.test', 'hasAttachments' => false],
            ['id' => 'm-old', 'subject' => 'Old', 'from' => 'Old@Vendor.example.test', 'receivedDateTime' => '2026-08-06T15:00:00Z', 'mailbox' => 'ap@example.test', 'hasAttachments' => false],
        ], $answer['messages']);
        self::assertSame([], $answer['mailboxErrors']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', self::runId($answer));
        // 7 Aug 2026 in Melbourne (UTC+10) is 6 Aug 14:00:00Z to 7 Aug 13:59:59Z, whole mailbox: no folder named.
        self::assertSame([['from' => '2026-08-06T14:00:00+00:00', 'to' => '2026-08-07T13:59:59+00:00', 'folder' => null, 'max' => 500]], $w->reader->listings);
    }

    public function test_the_window_follows_daylight_saving(): void
    {
        $w = $this->world();

        $w->list(['fromDate' => '2026-12-05', 'toDate' => '2026-12-05']);

        self::assertSame('2026-12-04T13:00:00+00:00', $w->reader->listings[0]['from']);
        self::assertSame('2026-12-05T12:59:59+00:00', $w->reader->listings[0]['to']);
    }

    public function test_a_listing_leaves_one_audit_row_in_the_shared_reader_and_the_run_is_running(): void
    {
        $w = $this->world();
        $w->message('m1');
        $answer = $w->list(["mailboxes" => ["ap@example.test"]]);

        self::assertCount(1, $w->mailAudit->events);
        self::assertSame('finance.mail.messages_listed', $w->mailAudit->events[0]->action);
        self::assertSame('AP', $w->mailAudit->events[0]->application);
        $run = $w->scans->find(self::runId($answer));
        self::assertInstanceOf(ApScanRun::class, $run);
        self::assertSame([ApScanRun::RUNNING, 1, 0, '2026-10-05', '2026-10-05', ['ap@example.test'], 'Dee Preparer', 7], [$run->status, $run->totalMessages, $run->errors, $run->fromDate, $run->toDate, $run->mailboxes, $run->requestedBy, $run->requestedByUserId]);
        self::assertSame('2026-10-09 01:32:03', $run->claimExpiresAt?->format('Y-m-d H:i:s'));
    }

    public function test_list_validates_the_window_with_the_sources_words(): void
    {
        $w = $this->world();
        foreach ([[[], 'fromDate and toDate are required'], [['fromDate' => '2026-10-05'], 'fromDate and toDate are required'], [['fromDate' => '', 'toDate' => '2026-10-05'], 'fromDate and toDate are required'],
            [['fromDate' => '2026-13-01', 'toDate' => '2026-10-05'], 'Dates must use YYYY-MM-DD'], [['fromDate' => '2026-8-1', 'toDate' => '2026-10-05'], 'Dates must use YYYY-MM-DD'],
            [['fromDate' => 20261005, 'toDate' => '2026-10-05'], 'Dates must use YYYY-MM-DD'],
            [['fromDate' => '2026-10-06', 'toDate' => '2026-10-05'], 'From date cannot be after to date']] as [$over, $message]) {
            try {
                $w->service->handle(ApScanWorld::actor(), ['mode' => 'list'] + $over);
                self::fail('Expected a refusal for ' . json_encode($over));
            } catch (ApInvalid $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
        self::assertSame([], $w->reader->listings, 'a refused request reads nothing and holds no claim');
        self::assertArrayHasKey('scanRunId', $w->list());
    }

    public function test_an_unknown_mode_names_it(): void
    {
        $w = $this->world();
        foreach ([[['mode' => 'bogus'], 'Unknown mode: bogus'], [[], 'Unknown mode: None'], [['mode' => null], 'Unknown mode: None']] as [$body, $message]) {
            try {
                $w->service->handle(ApScanWorld::actor(), $body);
                self::fail('Expected a refusal');
            } catch (ApInvalid $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
    }

    public function test_several_mailboxes_are_read_in_one_run_in_address_order_and_a_mailbox_named_twice_once(): void
    {
        $w = $this->world();
        $w->message('m-a', mailbox: ApScanWorld::MAILBOX, received: '2026-10-05 02:00:00');
        $w->message('m-a2', mailbox: ApScanWorld::MAILBOX, received: '2026-10-05 03:00:00');
        $w->message('m-b', mailbox: ApScanWorld::SECOND, received: '2026-10-05 04:00:00');

        $all = $w->list();
        self::assertSame(['m-b', 'm-a2', 'm-a'], array_column(self::rows($all, 'messages'), 'id'));
        self::assertSame(['ap2@example.test', 'ap@example.test', 'ap@example.test'], array_column(self::rows($all, 'messages'), 'mailbox'));

        $w2 = $this->world();
        $w2->message('m-a', mailbox: ApScanWorld::MAILBOX);
        $w2->message('m-b', mailbox: ApScanWorld::SECOND);
        $named = $w2->list(['mailboxes' => [' AP@Example.test', 'ap@example.test', 'closed@example.test', 'nobody@example.test']]);
        self::assertSame(['m-a'], array_column(self::rows($named, 'messages'), 'id'));
        self::assertCount(1, $w2->reader->listings, 'the mailbox named twice was listed once, the unreadable ones not at all');
    }

    public function test_a_mailbox_without_a_read_grant_or_outside_the_persons_scope_is_dropped_and_none_left_is_refused(): void
    {
        $w = $this->world();
        $w->message('m-a', mailbox: ApScanWorld::MAILBOX);
        $w->message('m-b', mailbox: ApScanWorld::SECOND);

        $scoped = $w->list([], ApScanWorld::actor([ApScanWorld::SECOND]));
        self::assertSame(['m-b'], array_column(self::rows($scoped, 'messages'), 'id'));

        foreach ([['mailboxes' => ['closed@example.test']], ['mailboxes' => ['nobody@example.test']]] as $over) {
            try {
                $w->list($over + ['confirmRescan' => true]);
                self::fail('Expected a refusal');
            } catch (ApInvalid $invalid) {
                self::assertSame('You do not have access to the active AP mailbox', $invalid->getMessage());
            }
        }
        try {
            $w->list(['confirmRescan' => true], ApScanWorld::actor(['other@example.test']));
            self::fail('Expected a refusal');
        } catch (ApInvalid $invalid) {
            self::assertSame('You do not have access to the active AP mailbox', $invalid->getMessage());
        }
        try {
            $w->list(['mailboxes' => 'ap@example.test']);
            self::fail('Expected a refusal');
        } catch (ApInvalid $invalid) {
            self::assertSame('mailboxes must be a list of addresses', $invalid->getMessage());
        }
    }

    public function test_one_mailbox_failing_is_recorded_and_the_others_go_on(): void
    {
        $w = $this->world();
        $w->message('m-a', mailbox: ApScanWorld::MAILBOX);
        $w->listing->failFor = ['ap2@example.test'];

        $answer = $w->list();

        self::assertSame(['m-a'], array_column(self::rows($answer, 'messages'), 'id'));
        self::assertSame([['mailbox' => 'ap2@example.test', 'error' => 'Graph is down for ap2@example.test']], $answer['mailboxErrors']);
        $run = $w->scans->find(self::runId($answer));
        self::assertSame([ApScanRun::RUNNING, 1, 1], [$run?->status, $run?->totalMessages, $run?->errors]);
    }

    public function test_every_mailbox_failing_with_nothing_found_fails_the_run_with_the_first_error_and_frees_the_claim(): void
    {
        $w = $this->world();
        $w->listing->failFor = ['ap2@example.test', 'ap@example.test'];

        try {
            $w->list();
            self::fail('Expected a 502');
        } catch (ApUpstream $upstream) {
            self::assertSame('Graph is down for ap2@example.test', $upstream->getMessage());
            self::assertSame(502, $upstream->httpStatus());
        }

        $w->listing->failFor = [];
        $again = $w->list();
        self::assertNotSame('', self::runId($again));
    }

    public function test_the_cap_keeps_the_newest_messages_of_each_mailbox(): void
    {
        $w = $this->world(maxScanMessages: 2);
        $w->message('m1', received: '2026-10-05 01:00:00');
        $w->message('m2', received: '2026-10-05 02:00:00');
        $w->message('m3', received: '2026-10-05 03:00:00');

        self::assertSame(['m3', 'm2'], array_column(self::rows($w->list(), 'messages'), 'id'));
    }

    public function test_a_long_window_is_read_in_slices_of_at_most_31_days_and_a_full_slice_is_split(): void
    {
        $w = $this->world();
        $w->message('m-july', received: '2026-07-10 00:00:00');
        $w->message('m-sept', received: '2026-09-20 00:00:00');
        $answer = $w->list(['fromDate' => '2026-07-01', 'toDate' => '2026-10-05']);
        self::assertSame(['m-sept', 'm-july'], array_column(self::rows($answer, 'messages'), 'id'));
        $perMailbox = count($w->reader->listings) / 2;
        self::assertGreaterThanOrEqual(4, $perMailbox);
        foreach ($w->reader->listings as $listing) {
            self::assertLessThanOrEqual(31 * 86400, strtotime($listing['to']) - strtotime($listing['from']));
        }

        $split = $this->world(listCap: 2);
        $split->message('m1', received: '2026-10-04 14:00:00');
        $split->message('m2', received: '2026-10-05 01:00:00');
        $split->message('m3', received: '2026-10-05 10:00:00');
        self::assertSame(['m3', 'm2', 'm1'], array_column(self::rows($split->list(), 'messages'), 'id'));
        self::assertGreaterThan(2, count($split->reader->listings));
    }

    // ==================================================================================
    // already scanned, the claim
    // ==================================================================================

    public function test_a_period_already_scanned_is_a_409_with_the_prior_runs_unless_confirmed(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $first = $w->list();
        $w->process('m1', runId: self::runId($first));
        $w->service->handle(ApScanWorld::actor(), ['mode' => 'complete', 'scanRunId' => self::runId($first)]);

        try {
            $w->list(['fromDate' => '2026-10-04', 'toDate' => '2026-10-06']);
            self::fail('Expected already scanned');
        } catch (ApAlreadyScanned $already) {
            self::assertSame('This period has already been scanned. Scanning it again may create duplicate invoices.', $already->getMessage());
            self::assertSame(409, $already->httpStatus());
            self::assertSame('FINANCE_AP_ALREADY_SCANNED', $already->errorCode());
            self::assertSame([
                'priorRuns' => [[
                    'id' => self::runId($first),
                    'fromDate' => '2026-10-05',
                    'toDate' => '2026-10-05',
                    'scannedAt' => '2026-10-09T01:02:03.456789+00:00',
                    'scannedBy' => 'Dee Preparer',
                    'totalMessages' => 1,
                    'invoiceEmails' => 1,
                    'status' => 'Complete',
                ]],
            ], $already->extra);
        }

        // "Scan anyway", and a period that does not overlap, both go ahead.
        self::assertNotSame(self::runId($first), self::runId($w->list(['confirmRescan' => true])));
        $w2 = $this->world();
        $w2->message("m1");
        $done = self::runId($w2->list());
        $w2->service->handle(ApScanWorld::actor(), ["mode" => "complete", "scanRunId" => $done]);
        self::assertNotSame($done, self::runId($w2->list(["fromDate" => "2026-10-07", "toDate" => "2026-10-07"])));
    }

    public function test_runs_that_read_nothing_or_failed_do_not_count_as_a_scan_of_the_period(): void
    {
        $w = $this->world();
        $empty = $w->list();
        $w->service->handle(ApScanWorld::actor(), ['mode' => 'complete', 'scanRunId' => self::runId($empty)]);
        self::assertArrayHasKey('scanRunId', $w->list(), 'a run that found no messages is not a prior scan');

        $failing = $this->world();
        $failing->listing->failFor = ['ap2@example.test', 'ap@example.test'];
        try {
            $failing->list();
        } catch (ApUpstream) {
        }
        $failing->listing->failFor = [];
        self::assertArrayHasKey('scanRunId', $failing->list(), 'a Failed run is not a prior scan');
    }

    public function test_a_second_list_while_a_scan_is_open_is_a_conflict_that_is_not_the_rescan_prompt(): void
    {
        $w = $this->world();
        $w->message('m1');
        $w->list();

        try {
            $w->list(['fromDate' => '2026-10-07', 'toDate' => '2026-10-07']);
            self::fail('Expected a conflict');
        } catch (ApConflict $conflict) {
            self::assertSame(409, $conflict->httpStatus());
            self::assertSame('FINANCE_AP_CONFLICT', $conflict->errorCode());
            self::assertSame('Another scan is in progress. Wait for it to finish, or stop it, before starting a new one.', $conflict->getMessage());
            self::assertSame([], $conflict->extra);
            self::assertDoesNotMatchRegularExpression('/already[ _]?scanned/i', $conflict->getMessage() . $conflict->errorCode());
        }
    }

    public function test_a_run_that_died_holding_the_claim_is_failed_once_it_has_expired(): void
    {
        $w = $this->world();
        $w->message('m1');
        $dead = self::runId($w->list());

        $w->clock->advance('+29 minutes');
        try {
            $w->list(['fromDate' => '2026-10-07', 'toDate' => '2026-10-07']);
            self::fail('Expected the claim to hold');
        } catch (ApConflict) {
        }
        $w->clock->advance('+2 minutes');
        $fresh = self::runId($w->list(['fromDate' => '2026-10-07', 'toDate' => '2026-10-07']));

        self::assertNotSame($dead, $fresh);
        self::assertSame(ApScanRun::FAILED, $w->scans->find($dead)?->status);
        self::assertSame(ApScanRun::RUNNING, $w->scans->find($fresh)?->status);
    }

    public function test_each_message_renews_the_claim_and_complete_releases_it(): void
    {
        $w = $this->world();
        $w->message('m1');
        $run = self::runId($w->list());

        $w->clock->advance('+25 minutes');
        $w->process('m1', runId: $run);
        $w->clock->advance('+25 minutes');
        try {
            $w->list(['fromDate' => '2026-10-07', 'toDate' => '2026-10-07']);
            self::fail('The renewed claim should still hold');
        } catch (ApConflict) {
        }

        $closed = $w->service->handle(ApScanWorld::actor(), ['mode' => 'complete', 'scanRunId' => $run]);
        self::assertSame('Complete', $closed['status']);
        self::assertNull($w->scans->find($run)?->claimExpiresAt);
        self::assertArrayHasKey('scanRunId', $w->list(['fromDate' => '2026-10-07', 'toDate' => '2026-10-07']));
    }

    // ==================================================================================
    // complete
    // ==================================================================================

    public function test_complete_answers_the_whole_run_row_and_works_from_failed_too(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $run = self::runId($w->list());
        $w->process('m1', runId: $run);

        $row = $w->service->handle(ApScanWorld::actor(), ['mode' => 'complete', 'scanRunId' => $run]);

        self::assertSame([
            'id' => $run,
            'requested_by' => 'Dee Preparer',
            'from_date' => '2026-10-05',
            'to_date' => '2026-10-05',
            'status' => 'Complete',
            'total_messages' => 1,
            'processed_messages' => 1,
            'invoice_emails' => 1,
            'other_emails' => 0,
            'duplicate_invoices' => 0,
            'errors' => 0,
            'created_date' => '2026-10-09T01:02:03.456789+00:00',
            'updated_date' => '2026-10-09T01:02:03.456789+00:00',
        ], $row);

        $failed = $this->world();
        $failed->listing->failFor = ['ap2@example.test', 'ap@example.test'];
        try {
            $failed->list();
        } catch (ApUpstream) {
        }
        $id = array_key_first((fn (): array => $this->runs)->call($failed->scans));
        self::assertSame(ApScanRun::FAILED, $failed->scans->find((string) $id)?->status);
        self::assertSame(2, self::runOf($failed, (string) $id)->errors);
        self::assertSame('Complete', $failed->service->handle(ApScanWorld::actor(), ['mode' => 'complete', 'scanRunId' => (string) $id])['status']);
    }

    public function test_complete_of_a_missing_run_is_not_found(): void
    {
        $w = $this->world();
        foreach ([['mode' => 'complete'], ['mode' => 'complete', 'scanRunId' => ''], ['mode' => 'complete', 'scanRunId' => 'nope']] as $body) {
            try {
                $w->service->handle(ApScanWorld::actor(), $body);
                self::fail('Expected not found');
            } catch (ApNotFound $notFound) {
                self::assertSame('Scan run not found', $notFound->getMessage());
            }
        }
    }

    // ==================================================================================
    // process: the first message
    // ==================================================================================

    public function test_a_message_with_an_invoice_is_captured_and_the_email_classified(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)], received: '2026-10-05 01:30:00');
        $run = self::runId($w->list());

        $answer = $w->process('m1', runId: $run);

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $answer);
        $invoices = $w->allInvoices();
        self::assertCount(1, $invoices);
        $invoice = $invoices[0];
        $email = $w->email('m1');
        self::assertSame(['m1', 'a1', 'ap@example.test', 'billing@vendor.example.test', 'Invoice please', 'inv.pdf', $email->id], [$invoice->emailId, $invoice->attachmentId, $invoice->mailbox, $invoice->sender, $invoice->subject, $invoice->attachment, $invoice->emailRecordId]);
        self::assertSame('2026-10-05 01:30:00', $invoice->emailDate?->format('Y-m-d H:i:s'), 'the full instant is kept');
        self::assertSame(['INV-42', 'Acme Pty Ltd', 'AUD', 110.0, 'In Progress', null, 0.93], [$invoice->invoiceNo, $invoice->vendor, $invoice->currency, $invoice->totalAmount, $invoice->status, $invoice->reviewReason, $invoice->extractionConfidence]);
        self::assertSame(hash('sha256', self::PDF), $invoice->contentHash);
        self::assertSame('ap@acme.example.test', $invoice->vendorEmail);
        self::assertSame([ApInvoice::DUPLICATE_CLEAR, null], [$invoice->duplicateStatus, $invoice->duplicateOfInvoiceId]);
        self::assertNotNull($invoice->fileId);
        self::assertCount(1, $w->fileStore->blobPaths());

        self::assertSame([ApEmail::INVOICE, 'Tax invoice for cable.', 0.93, ApEmail::UNVERIFIED, $run, 'Thanks, we will check.'], [$email->classification, $email->classificationReason, $email->classificationConfidence, $email->reviewStatus, $email->scanRunId, $email->proposedResponse]);
        self::assertSame(['Vera Vendor', 'billing@vendor.example.test', 'Preview of m1'], [$email->senderName, $email->senderEmail, $email->bodyPreview]);
        $files = $w->emails->attachmentsOf($email->id);
        self::assertCount(1, $files);
        self::assertSame(['a1', 'inv.pdf', 'application/pdf', true, $invoice->id, $invoice->fileId, hash('sha256', self::PDF), null], [$files[0]->graphAttachmentId, $files[0]->name, $files[0]->contentType, $files[0]->isInvoice, $files[0]->invoiceId, $files[0]->fileId, $files[0]->contentHash, $files[0]->duplicateOfInvoiceId]);
        $stored = $w->scans->find($run);
        self::assertSame([1, 1, 0, 0, 0], [$stored?->processedMessages, $stored?->invoiceEmails, $stored?->otherEmails, $stored?->duplicateInvoices, $stored?->errors]);
        self::assertSame([], $w->audit->all(), 'the scan writes no audit event of its own');
    }

    public function test_the_company_and_the_vendors_address_are_resolved(): void
    {
        $w = $this->world(internalDomains: []);
        $w->references->insert(new ApReferenceRow('companies', 'c1', ['code' => 'TCA', 'name' => 'TriCab (Australia) Pty Ltd', 'sites' => '', 'former_names' => ''], true, ApScanWorld::utc('2026-10-01 00:00:00')));
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $w->ai->invoice(['company' => 'TRICAB (AUSTRALIA) PTY LTD', 'vendor_email' => 'Accounts@Example.test']);

        $w->process('m1');

        $invoice = $w->allInvoices()[0];
        self::assertSame('TriCab (Australia) Pty Ltd', $invoice->company);
        // The printed address is on our own domain (the mailbox's), so the sender's is taken.
        self::assertSame('billing@vendor.example.test', $invoice->vendorEmail);
        $call = $w->ai->calls[0];
        self::assertSame('ap_classify_extract', $call['purpose']);
        self::assertSame(['Invoice please', 'billing@vendor.example.test'], [$call['data']['subject'], $call['data']['sender']]);
        self::assertSame([['name' => 'TriCab (Australia) Pty Ltd', 'sites' => '', 'former_names' => '']], $call['data']['companies']);
    }

    public function test_a_message_without_a_supported_file_is_other_and_says_so(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::sized('d1', 'terms.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 90000)]);
        $w->message('m2');

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $w->process('m1'));
        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $w->process('m2'));

        foreach (['m1', 'm2'] as $id) {
            $email = $w->email($id);
            self::assertSame('No supported PDF/image invoice attachment; manual verification required', $email->classificationReason);
            self::assertSame([ApEmail::OTHER, 0.0, ApEmail::UNVERIFIED], [$email->classification, $email->classificationConfidence, $email->reviewStatus]);
        }
        self::assertSame(0, $w->ai->count('ap_classify_extract'));
        self::assertSame(2, $w->ai->count('ap_reply_suggest'), 'one suggestion per scanned email, attachments or not');
    }

    public function test_only_pdfs_and_real_images_are_read_and_a_missing_extension_comes_from_the_type(): void
    {
        $w = $this->world();
        $w->message('m1', [
            ApScanWorld::sized('a1', 'logo.png', 'image/png', 1200),
            ApScanWorld::sized('a2', 'sig.png', 'image/png', 90000, inline: true),
            ApScanWorld::sized('a3', 'photo.png', 'image/png', 90000, 'PNG-BYTES'),
            ApScanWorld::sized('a4', 'Invoice 12345', 'application/pdf; charset=binary', 90, 'NOEXT-PDF'),
            ApScanWorld::sized('a5', 'report.docx', 'application/pdf', 90, 'DOCX-AS-PDF'),
            ApScanWorld::sized('a6', 'cat.gif', 'image/gif', 90000),
            ApScanWorld::sized('a7', 'apple.pdf', 'application/pdf', 90, 'APPLE-INLINE', inline: true),
            ApScanWorld::sized('a8', 'sheet.xlsx', 'application/vnd.ms-excel', 90000),
        ]);
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $w->ai->invoice(['invoice_no' => 'N-' . $n]);
        }

        $answer = $w->process('m1');

        self::assertSame(4, $answer['captured']);
        $names = array_map(static fn (ApInvoice $i): string => $i->attachment, $w->allInvoices());
        sort($names);
        self::assertSame(['Invoice 12345.pdf', 'apple.pdf', 'photo.png', 'report.docx.pdf'], $names);
        self::assertSame(['a4', 'a7', 'a3', 'a5'], array_map(static fn (string $n): ?string => $w->invoiceNamed($n)->attachmentId, $names));
        self::assertSame(4, $w->ai->count('ap_classify_extract'));
        $types = [];
        foreach ($w->ai->calls as $call) {
            if ($call['purpose'] === 'ap_classify_extract') {
                $file = $call['data']['file'] ?? null;
                self::assertIsArray($file);
                $types[] = $file['content_type'] ?? null;
            }
        }
        self::assertSame(['image/png', 'application/pdf', 'application/pdf', 'application/pdf'], $types);
    }

    public function test_same_named_attachments_are_separate_invoices_and_a_rescan_adds_none(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'Invoice.pdf', 'ONE'), ApScanWorld::pdf('a2', 'Invoice.pdf', 'TWO'), ApScanWorld::pdf('a3', 'Invoice.pdf', 'THREE')]);
        $w->ai->invoice(['invoice_no' => 'S-1']);
        $w->ai->invoice(['invoice_no' => 'S-2']);
        $w->ai->invoice(['invoice_no' => 'S-3']);

        self::assertSame(3, $w->process('m1')['captured']);
        $again = $w->process('m1');

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $again);
        self::assertCount(3, $w->allInvoices());
        self::assertCount(3, $w->emails->attachmentsOf($w->email('m1')->id));
        self::assertSame(3, $w->ai->count('ap_classify_extract'));
        self::assertSame('Previously captured invoice: Invoice.pdf; Previously captured invoice: Invoice.pdf; Previously captured invoice: Invoice.pdf', $w->email('m1')->classificationReason);
    }

    public function test_a_long_or_odd_file_name_never_fails_the_scan_and_keeps_its_extension(): void
    {
        $w = $this->world();
        $long = str_repeat('x', 300) . '.pdf';
        $w->message('m1', [ApScanWorld::pdf('a1', $long, 'LONG'), ApScanWorld::pdf('a2', "Faktura\x07 Müller", 'ODD')]);
        $w->ai->invoice(['invoice_no' => 'L-1']);
        $w->ai->invoice(['invoice_no' => 'L-2']);

        self::assertSame(['captured' => 2, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $w->process('m1'));

        $long = $w->invoiceNamed(mb_substr(str_repeat("x", 300) . ".pdf", 0, 251) . ".pdf");
        self::assertSame(255, mb_strlen($long->attachment));
        self::assertStringEndsWith(".pdf", $long->attachment);
        self::assertSame("Faktura  Müller.pdf", $w->invoiceNamed("Faktura  Müller.pdf")->attachment, "a control character becomes a space and the type adds the suffix");
        $rows = array_map(static fn ($row): string => $row->name, $w->emails->attachmentsOf($w->email("m1")->id));
        sort($rows);
        self::assertSame(["Faktura  Müller.pdf", $long->attachment], $rows);
    }

    // ==================================================================================
    // process: duplicates, tombstones
    // ==================================================================================

    public function test_an_exact_copy_of_an_invoice_files_nothing_and_never_reaches_the_model(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $w->message('m2', [ApScanWorld::pdf('b1', 'resent.pdf', self::PDF)], received: '2026-10-05 02:00:00');
        $w->process('m1');
        $original = $w->allInvoices()[0];

        $answer = $w->process('m2');

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 1, 'classification' => 'Invoice'], $answer);
        self::assertSame(1, $w->ai->count('ap_classify_extract'), 'no model call for the copy');
        self::assertCount(1, $w->allInvoices());
        self::assertCount(1, $w->fileStore->blobPaths(), 'no file stored for the copy');
        self::assertSame('Exact duplicate attachment: resent.pdf', $w->email('m2')->classificationReason);
        $row = $w->emails->attachmentsOf($w->email('m2')->id)[0];
        self::assertSame([true, null, $original->id, hash('sha256', self::PDF), null], [$row->isInvoice, $row->invoiceId, $row->duplicateOfInvoiceId, $row->contentHash, $row->fileId]);
        self::assertSame([$w->email('m2')->id => [$original->id]], $w->emails->duplicateInvoiceIdsByEmail([$w->email('m2')->id]));
    }

    public function test_a_binned_invoice_still_blocks_an_exact_copy_and_a_recapture(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $w->message('m2', [ApScanWorld::pdf('b1', 'copy.pdf', self::PDF)], received: '2026-10-05 02:00:00');
        $w->process('m1');
        $invoice = $w->allInvoices()[0];
        $w->invoices->bin($invoice->id, 7, 'dee@example.test', $w->clock->now());

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 1, 'classification' => 'Invoice'], $w->process('m2'));
        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $w->process('m1'));
        self::assertSame('Previously captured invoice: inv.pdf', $w->email('m1')->classificationReason);
        self::assertCount(1, $w->allInvoices(binned: true));
        self::assertSame([], $w->allInvoices());
        self::assertSame(1, $w->ai->count('ap_classify_extract'));
    }

    public function test_a_capture_deleted_for_good_does_not_come_back_by_attachment_id_or_by_name(): void
    {
        foreach (['a1', 'inv.pdf'] as $tombstoneKey) {
            $w = $this->world();
            $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
            $w->process('m1');
            $invoice = $w->allInvoices()[0];
            $w->invoices->purge($invoice->id, 'm1', $tombstoneKey, $w->clock->now());

            $answer = $w->process('m1');

            self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $answer, $tombstoneKey);
            self::assertSame([], $w->allInvoices());
            self::assertSame(1, $w->ai->count('ap_classify_extract'), 'the tombstoned file is not sent to the model again');
        }
    }

    public function test_a_different_file_with_the_same_invoice_is_captured_and_flagged_as_a_potential_duplicate(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', 'FIRST-COPY')]);
        $w->message('m2', [ApScanWorld::pdf('b1', 'inv-rescan.pdf', 'SECOND-COPY')], received: '2026-10-05 02:00:00');
        $run = self::runId($w->list());
        $w->process('m1', runId: $run);
        $first = $w->invoiceNamed("inv.pdf");

        $answer = $w->process('m2', runId: $run);

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 1, 'classification' => 'Invoice'], $answer);
        $second = $w->invoiceNamed("inv-rescan.pdf");
        self::assertSame([ApInvoice::DUPLICATE_POTENTIAL, $first->id, 'Possible duplicate: same vendor, invoice number, currency, and total'], [$second->duplicateStatus, $second->duplicateOfInvoiceId, $second->duplicateReason]);
        $row = $w->emails->attachmentsOf($w->email('m2')->id)[0];
        self::assertSame([$second->id, $first->id], [$row->invoiceId, $row->duplicateOfInvoiceId]);
        self::assertSame(1, $w->scans->find($run)?->duplicateInvoices);
    }

    // ==================================================================================
    // process: grading, failures
    // ==================================================================================

    public function test_a_low_confidence_invoice_needs_review_with_the_reason_and_the_weakest_file_drives_the_email(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'weak.pdf', 'WEAK'), ApScanWorld::pdf('a2', 'good.pdf', 'GOOD')]);
        $w->ai->invoice(['invoice_no' => 'W-1', 'extraction_confidence' => 0.41]);
        $w->ai->invoice(['invoice_no' => 'W-2', 'extraction_confidence' => 0.97, 'classification_reason' => 'Looks fine.']);

        self::assertSame(2, $w->process('m1')['captured']);

        $weak = $w->invoiceNamed("weak.pdf");
        $good = $w->invoiceNamed("good.pdf");
        self::assertSame([ApInvoice::STATUS_NEEDS_REVIEW, 'Low extraction confidence (41%)', 0.41], [$weak->status, $weak->reviewReason, $weak->extractionConfidence]);
        self::assertSame([ApInvoice::STATUS_IN_PROGRESS, null, 0.97], [$good->status, $good->reviewReason, $good->extractionConfidence]);
        $email = $w->email('m1');
        self::assertSame(0.41, $email->classificationConfidence);
        self::assertSame('Tax invoice for cable.; Looks fine.', $email->classificationReason);
    }

    public function test_a_model_failure_is_counted_leaves_no_trace_and_the_next_scan_retries_the_file(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $run = self::runId($w->list());
        $w->ai->queue('ap_classify_extract', FinanceAiFailure::of(FinanceAiFailure::UNAVAILABLE, 'The AI service is unavailable.'));

        $answer = $w->process('m1', runId: $run);

        self::assertSame(['captured' => 0, 'errors' => 1, 'duplicates' => 0, 'classification' => 'Other'], $answer);
        $email = $w->email('m1');
        self::assertSame('Could not classify inv.pdf; manual verification required', $email->classificationReason);
        self::assertSame([0.0, ApEmail::NEEDS_REVIEW], [$email->classificationConfidence, $email->reviewStatus]);
        self::assertSame([], $w->emails->attachmentsOf($email->id), 'no file row, so nothing marks the file as seen');
        self::assertSame([], $w->allInvoices());
        self::assertSame([], $w->fileStore->blobPaths(), 'the stored file is taken back');
        self::assertSame(1, self::runOf($w, $run)->errors);
        self::assertNotSame([], $w->log);

        $retry = $w->process('m1', runId: $run);

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $retry);
        self::assertSame(ApEmail::NEEDS_REVIEW, $w->email('m1')->reviewStatus, '`Needs review` is sticky until a person verifies');
        self::assertSame(1, self::runOf($w, $run)->errors);
    }

    public function test_an_attachment_that_cannot_be_read_is_an_error_not_a_failed_message(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'bad.pdf', 'X'), ApScanWorld::pdf('a2', 'empty.pdf', ''), ApScanWorld::pdf('a3', 'good.pdf', 'GOOD')]);
        $w->reader->failAttachments = ['a1'];

        $answer = $w->process('m1');

        self::assertSame(['captured' => 1, 'errors' => 2, 'duplicates' => 0, 'classification' => 'Invoice'], $answer);
        self::assertSame('Could not classify bad.pdf; manual verification required; Could not classify empty.pdf; manual verification required; Tax invoice for cable.', $w->email('m1')->classificationReason);
        self::assertSame(ApEmail::NEEDS_REVIEW, $w->email('m1')->reviewStatus);
    }

    public function test_a_file_the_model_says_is_not_an_invoice_is_recorded_discarded_and_sent_again_next_time(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'remit.pdf', 'REMIT')]);
        $w->ai->notInvoice('A remittance advice, not an invoice.');
        $w->ai->notInvoice(null);

        $first = $w->process('m1');

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $first);
        $email = $w->email('m1');
        self::assertSame(['A remittance advice, not an invoice.', 0.0, ApEmail::UNVERIFIED], [$email->classificationReason, $email->classificationConfidence, $email->reviewStatus]);
        $row = $w->emails->attachmentsOf($email->id)[0];
        self::assertSame([false, hash('sha256', 'REMIT'), null, null], [$row->isInvoice, $row->contentHash, $row->invoiceId, $row->fileId]);
        self::assertSame([], $w->fileStore->blobPaths());
        self::assertSame([], $w->allInvoices());

        // R17, kept as the source has it: nothing marks the file as known, so a re-scan asks the model again.
        $w->process('m1');
        self::assertSame(2, $w->ai->count('ap_classify_extract'));
        self::assertSame('Not an invoice: remit.pdf', $w->email('m1')->classificationReason);
        self::assertCount(1, $w->emails->attachmentsOf($email->id));
    }

    // ==================================================================================
    // process: the email's own rules
    // ==================================================================================

    public function test_a_verified_email_keeps_its_classification_and_reason_though_the_invoice_is_still_captured(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $w->process('m1');
        $w->emails->update($w->email('m1')->id, ['classification' => 'Other', 'classification_reason' => 'Not ours', 'review_status' => 'Verified'], $w->clock->now());
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF), ApScanWorld::pdf('a2', 'second.pdf', 'SECOND')]);
        $w->ai->invoice(['invoice_no' => 'V-2']);

        $answer = $w->process('m1');

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $answer);
        $email = $w->email('m1');
        self::assertSame(['Other', 'Not ours', 'Verified'], [$email->classification, $email->classificationReason, $email->reviewStatus]);
        self::assertCount(2, $w->allInvoices());

        $bare = $this->world();
        $bare->message('m2', [ApScanWorld::pdf('a1', 'x.pdf', 'X')]);
        $bare->process('m2');
        $bare->emails->update($bare->email('m2')->id, ['classification_reason' => null, 'review_status' => 'Verified'], $bare->clock->now());
        $bare->process('m2');
        self::assertSame('Manually verified', $bare->email('m2')->classificationReason);
    }

    public function test_the_reply_suggestion_is_stored_once_never_overwritten_and_off_means_no_call(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $w->process('m1');
        self::assertSame('Thanks, we will check.', $w->email('m1')->proposedResponse);
        self::assertSame(1, $w->ai->count('ap_reply_suggest'));
        self::assertSame(['sender_name' => 'Vera Vendor', 'sender' => 'billing@vendor.example.test', 'sender_email' => 'billing@vendor.example.test', 'subject' => 'Invoice please', 'body_preview' => 'Preview of m1'], $w->ai->calls[1]['data']['email']);

        $w->emails->update($w->email('m1')->id, ['proposed_response' => 'My edited reply'], $w->clock->now());
        $w->process('m1');
        self::assertSame('My edited reply', $w->email('m1')->proposedResponse);
        self::assertSame(1, $w->ai->count('ap_reply_suggest'), 'a stored suggestion is not asked for again');

        $off = $this->world(suggest: false);
        $off->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $off->process('m1');
        self::assertNull($off->email('m1')->proposedResponse);
        self::assertSame(0, $off->ai->count('ap_reply_suggest'));
    }

    public function test_an_empty_suggestion_is_stored_empty_and_tried_again_next_scan(): void
    {
        $w = $this->world();
        $w->message('m1');
        $w->ai->queue('ap_reply_suggest', FinanceAiFailure::of(FinanceAiFailure::UNAVAILABLE, 'down'));

        $w->process('m1');
        self::assertSame('', $w->email('m1')->proposedResponse);
        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $w->process('m1'));

        self::assertSame('Thanks, we will check.', $w->email('m1')->proposedResponse);
        self::assertSame(2, $w->ai->count('ap_reply_suggest'));
    }

    public function test_a_binned_email_is_left_alone_and_not_restored_unless_forced(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $w->process('m1');
        $before = $w->email('m1');
        $w->emails->bin($before->id, 7, 'dee@example.test', $w->clock->now());
        $w->emails->update($before->id, ['classification' => 'Other'], $w->clock->now());
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)], subject: 'Changed upstream');
        $run = self::runId($w->list());

        $answer = $w->process('m1', runId: $run);

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'binned' => true, 'classification' => 'Other'], $answer);
        $after = $w->email('m1');
        self::assertTrue($after->isBinned(), 'not restored');
        self::assertSame($before->classificationReason, $after->classificationReason, 'nothing else was written');
        $stored = $w->scans->find($run);
        self::assertSame([1, 0, 0], [$stored?->processedMessages, $stored?->invoiceEmails, $stored?->otherEmails]);
    }

    public function test_a_forced_capture_takes_a_file_the_model_rejected_and_flags_it(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'statement.pdf', 'STATEMENT')]);
        $run = self::runId($w->list());
        $w->ai->notInvoice();
        $w->process("m1", runId: $run);
        self::assertSame('Other', $w->email('m1')->classification);
        $w->emails->bin($w->email('m1')->id, 7, 'dee@example.test', $w->clock->now());
        $w->ai->notInvoice();

        $answer = $w->process('m1', force: true);

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $answer);
        $invoice = $w->allInvoices()[0];
        self::assertSame([ApInvoice::STATUS_NEEDS_REVIEW, 'Captured by hand from the AP Mailbox; the AI did not read this file as an invoice'], [$invoice->status, $invoice->reviewReason]);
        $email = $w->email('m1');
        self::assertSame(['Invoice', null], [$email->classification, $email->scanRunId], 'the run id is cleared by a forced capture');
        self::assertTrue($email->isBinned(), 'a forced capture does not restore the email');
        $stored = $w->scans->find($run);
        self::assertSame([1, 0, 1], [$stored?->processedMessages, $stored?->invoiceEmails, $stored?->otherEmails], 'no run counter moved');

        // A repeat is harmless: the file is already captured.
        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $w->process('m1', force: true));
        self::assertCount(1, $w->allInvoices());
    }

    // ==================================================================================
    // process: refusals and failures
    // ==================================================================================

    public function test_process_needs_a_message_and_a_mailbox_and_checks_the_persons_scope(): void
    {
        $w = $this->world();
        $w->message('m1');
        foreach ([[['mode' => 'process'], ''], [['mode' => 'process', 'messageId' => 'm1'], ''], [['mode' => 'process', 'mailbox' => 'ap@example.test'], '']] as [$body]) {
            try {
                $w->service->handle(ApScanWorld::actor(), $body);
                self::fail('Expected a refusal');
            } catch (ApInvalid $invalid) {
                self::assertSame('messageId and mailbox are required', $invalid->getMessage());
            }
        }
        try {
            $w->process('m1', actor: ApScanWorld::actor([ApScanWorld::SECOND]));
            self::fail('Expected a refusal');
        } catch (ApForbidden $forbidden) {
            self::assertSame('You do not have access to this mailbox', $forbidden->getMessage());
        }
        self::assertSame(0, $w->mailbox->reads, 'nothing was read for a refused request');
        self::assertNull($w->emails->findByMessage('ap@example.test', 'm1'));
    }

    public function test_process_checks_the_read_grant_and_the_access_scope_in_the_reader(): void
    {
        $w = $this->world();
        $w->message('m1', mailbox: ApScanWorld::CLOSED);
        try {
            $w->process('m1', ApScanWorld::CLOSED);
            self::fail('Expected a 403');
        } catch (ApForbidden $forbidden) {
            self::assertSame('AP is not allowed to read the mailbox closed@example.test.', $forbidden->getMessage());
        }
        try {
            $w->process('m1', 'nobody@example.test');
            self::fail('Expected a 403');
        } catch (ApForbidden) {
        }
        self::assertNull($w->emails->findByMessage('closed@example.test', 'm1'));

        $w->message('m2');
        $w->scopes->grant(7, 'AP', 'mailbox', 'ap2@example.test');
        try {
            $w->process('m2');
            self::fail('Expected a 403 from the access scope');
        } catch (ApForbidden $forbidden) {
            self::assertSame('AP is not allowed to read the mailbox ap@example.test.', $forbidden->getMessage());
        }
    }

    public function test_a_message_that_cannot_be_read_is_a_502_and_stores_nothing(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $run = self::runId($w->list());
        $w->reader->failMessages = ['m1'];

        try {
            $w->process('m1', runId: $run);
            self::fail('Expected a 502');
        } catch (ApUpstream $upstream) {
            self::assertSame('Could not fetch the message from Outlook: Graph could not read that message', $upstream->getMessage());
        }
        $w->reader->failMessages = [];
        $w->reader->failAttachmentListing = true;
        try {
            $w->process('m1', runId: $run);
            self::fail('Expected a 502');
        } catch (ApUpstream $upstream) {
            self::assertSame('Could not fetch the message from Outlook: Graph could not list attachments', $upstream->getMessage());
        }

        self::assertNull($w->emails->findByMessage('ap@example.test', 'm1'));
        self::assertSame(0, $w->scans->find($run)?->processedMessages);
    }

    public function test_an_unknown_run_id_is_ignored_and_the_mailbox_is_stored_lower_case(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);

        $answer = $w->process('m1', 'AP@Example.TEST', 'no-such-run');

        self::assertSame('Invoice', $answer['classification']);
        self::assertSame(['ap@example.test', null], [$w->email('m1')->mailbox, $w->email('m1')->scanRunId]);
    }

    public function test_a_message_id_too_long_to_store_is_refused_rather_than_cut(): void
    {
        $w = $this->world();
        try {
            $w->process(str_repeat('M', 201));
            self::fail('Expected a refusal');
        } catch (ApInvalid $invalid) {
            self::assertSame('The message id is too long to store', $invalid->getMessage());
        }
    }

    // ==================================================================================
    // process twice, several mailboxes
    // ==================================================================================

    public function test_a_message_processed_twice_leaves_one_email_one_file_row_and_one_invoice(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $run = self::runId($w->list());

        $first = $w->process('m1', runId: $run);
        $second = $w->process('m1', runId: $run);

        self::assertSame(1, $first['captured']);
        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $second);
        self::assertCount(1, $w->allInvoices());
        self::assertCount(1, $w->emails->attachmentsOf($w->email('m1')->id));
        self::assertCount(1, $w->fileStore->blobPaths());
        self::assertSame(1, $w->ai->count('ap_classify_extract'));
        self::assertSame(1, $w->ai->count('ap_reply_suggest'));
        $email = $w->email('m1');
        self::assertSame('Previously captured invoice: inv.pdf', $email->classificationReason);
        self::assertSame(1, $w->emails->counts(null, null, null)['total']);
    }

    public function test_a_file_row_race_lost_to_another_request_is_a_skip_not_an_error(): void
    {
        $w = $this->world();
        $w->message('m1', [ApScanWorld::pdf('a1', 'inv.pdf', self::PDF)]);
        $racing = new RacingApEmailStore($w->emails);
        $time = new \TriCab\Core\Finance\Ap\Service\ApTime($w->clock, 'Australia/Melbourne');
        $emailService = new \TriCab\Core\Finance\Ap\Service\ApEmailService($racing, $w->invoices, $w->audit, $time);
        $service = new \TriCab\Core\Finance\Ap\Service\ApScanService(
            $w->scans, new \TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry([new \TriCab\Core\Finance\Shared\Mail\FinanceMailbox('0000000000000000000000000000000a', ApScanWorld::MAILBOX, 'AP', 'AP', true, ['AP' => ['read' => true, 'send' => false]])]),
            $w->reader, $emailService, $racing, $w->invoiceService, $w->invoices, $w->attachments,
            new \TriCab\Core\Finance\Ap\ApAiService($w->ai), $w->references, $time,
        );
        $racing->failNextAttachmentSave = true;

        $answer = $service->handle(ApScanWorld::actor(), ['mode' => 'process', 'messageId' => 'm1', 'mailbox' => ApScanWorld::MAILBOX]);

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $answer);
        self::assertSame([], $w->fileStore->blobPaths(), 'the loser takes its kept file back');
    }

    public function test_several_mailboxes_in_one_run_each_message_is_processed_in_its_own_mailbox(): void
    {
        $w = $this->world();
        $w->message('m-a', [ApScanWorld::pdf('a1', 'a.pdf', 'AAA')], mailbox: ApScanWorld::MAILBOX);
        $w->message('m-b', [ApScanWorld::pdf('a1', 'b.pdf', 'BBB')], mailbox: ApScanWorld::SECOND);
        $w->message('m-c', [], mailbox: ApScanWorld::SECOND, received: '2026-10-05 02:00:00');
        $listed = $w->list();
        $run = self::runId($listed);
        $w->ai->invoice(['invoice_no' => 'MB-1']);
        $w->ai->invoice(['invoice_no' => 'MB-2']);

        foreach (self::rows($listed, 'messages') as $message) {
            self::assertIsString($message['id']);
            self::assertIsString($message['mailbox']);
            $w->process($message['id'], $message['mailbox'], $run);
        }

        $mailboxes = array_map(static fn (ApInvoice $i): string => $i->mailbox ?? '', $w->allInvoices());
        sort($mailboxes);
        self::assertSame(['ap2@example.test', 'ap@example.test'], $mailboxes);
        $stored = $w->scans->find($run);
        self::assertSame([3, 3, 2, 1], [$stored?->totalMessages, $stored?->processedMessages, $stored?->invoiceEmails, $stored?->otherEmails]);
        self::assertSame('ap2@example.test', $w->email('m-b', ApScanWorld::SECOND)->mailbox);
    }

    public function test_the_scan_has_no_exact_wording_drift_in_its_constants(): void
    {
        self::assertSame('Captured by hand from the AP Mailbox; the AI did not read this file as an invoice', ApExtraction::FORCED_REVIEW_REASON);
        self::assertSame(30, \TriCab\Core\Finance\Ap\Service\ApScanService::CLAIM_MINUTES);
    }
}
