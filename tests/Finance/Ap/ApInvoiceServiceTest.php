<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;

/**
 * The register on the in-memory twin: the list and its parameters, the derived Overdue, manual capture and its grade, the
 * PATCH whitelist and messages, the Bin, restore and permanent delete, and the audit events (T6.4).
 */
final class ApInvoiceServiceTest extends TestCase
{
    private ApInvoiceWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApInvoiceWorld();
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private static function refused(string $class, string $message, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $failure) {
            self::assertInstanceOf($class, $failure);
            self::assertSame($message, $failure->getMessage());

            return;
        }
        self::fail('Expected ' . $class . ': ' . $message);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function listed(array $params = [], ?\TriCab\Core\Finance\Ap\Domain\ApActor $actor = null): array
    {
        return ApInvoiceFixtures::ids($this->world->service->list($actor ?? $this->world->actor(), $params));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function created(array $body): array
    {
        return $this->world->service->create($this->world->actor(), $body);
    }

    // ---- the list ---------------------------------------------------------------------------------

    public function test_the_list_defaults_to_the_newest_created_first_and_hides_the_bin(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('b', createdAt: ApInvoiceFixtures::utc('2026-10-03 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('c', createdAt: ApInvoiceFixtures::utc('2026-10-02 00:00:00'), deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00')));

        self::assertSame(['b', 'a'], $this->listed());
        self::assertSame(['a', 'b'], $this->listed(['sort' => 'created_date']));
        self::assertSame(['c'], $this->listed(['deleted' => '1', 'sort' => '-deleted_at', 'limit' => '1000']));
        self::assertSame(['c'], $this->listed(['deleted' => 'true']));
        self::assertSame(['b', 'a'], $this->listed(['deleted' => 'yes']));
        self::assertSame(['a'], $this->listed(['id' => 'a']));
    }

    public function test_a_column_that_is_not_on_the_sort_list_sorts_by_created_date_with_the_direction_kept(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00'), notes: 'zzz'));
        $this->world->stored(ApInvoiceFixtures::invoice('b', createdAt: ApInvoiceFixtures::utc('2026-10-02 00:00:00'), notes: 'aaa'));

        // Long text is not sortable (Q5): notes, review_reason, duplicate_reason, vendor_match_note, content_hash, nonsense.
        foreach (['notes', '-notes', 'review_reason', 'duplicate_reason', 'vendor_match_note', 'content_hash', 'no_such_column', 'id; DROP TABLE x'] as $sort) {
            self::assertSame(str_starts_with($sort, '-') ? ['b', 'a'] : ['a', 'b'], $this->listed(['sort' => $sort]), $sort);
        }
    }

    public function test_the_sort_list_orders_by_a_bounded_column_with_the_id_breaking_ties(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', vendor: 'Bravo', totalAmount: 5.0));
        $this->world->stored(ApInvoiceFixtures::invoice('b', vendor: 'alpha', totalAmount: 5.0));
        $this->world->stored(ApInvoiceFixtures::invoice('c', vendor: 'Charlie', totalAmount: null));

        self::assertSame(['b', 'a', 'c'], $this->listed(['sort' => 'vendor']));
        self::assertSame(['c', 'a', 'b'], $this->listed(['sort' => '-vendor']));
        self::assertSame(['c', 'a', 'b'], $this->listed(['sort' => 'total_amount']), 'null first, then the tie by id');
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function badLimits(): array
    {
        return [
            'zero' => ['0', 'limit must be at least 1'],
            'minus one is not "no limit"' => ['-1', 'limit must be at least 1'],
            'minus five' => ['-5', 'limit must be at least 1'],
            'text' => ['abc', 'limit must be a whole number'],
            'decimal' => ['2.5', 'limit must be a whole number'],
            'a list' => [['1'], 'limit must be a whole number'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLimits')]
    public function test_a_limit_that_is_not_a_positive_whole_number_is_refused_never_read_as_no_limit(mixed $limit, string $message): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a'));

        self::refused(ApInvalid::class, $message, fn () => $this->world->service->list($this->world->actor(), ['limit' => $limit]));
    }

    public function test_the_limit_defaults_to_200_and_is_capped_at_1000(): void
    {
        for ($i = 0; $i < 1003; ++$i) {
            $this->world->stored(ApInvoiceFixtures::invoice(sprintf('i%04d', $i)));
        }

        self::assertCount(200, $this->listed());
        self::assertCount(5, $this->listed(['limit' => '5']));
        self::assertCount(1000, $this->listed(['limit' => '5000']));
        self::assertCount(1000, $this->listed(['limit' => '1000']));
    }

    public function test_the_period_keeps_a_day_either_side_and_files_a_hand_capture_by_the_day_it_was_captured(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('before', emailDate: ApInvoiceFixtures::utc('2026-09-29 23:59:59')));
        $this->world->stored(ApInvoiceFixtures::invoice('edgeBefore', emailDate: ApInvoiceFixtures::utc('2026-09-30 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('inside', emailDate: ApInvoiceFixtures::utc('2026-10-02 05:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('edgeAfter', emailDate: ApInvoiceFixtures::utc('2026-10-04 23:59:59')));
        $this->world->stored(ApInvoiceFixtures::invoice('after', emailDate: ApInvoiceFixtures::utc('2026-10-05 00:00:00')));
        // A hand capture has no email date: it is filed by created_date, not by the invoice's own date.
        $this->world->stored(ApInvoiceFixtures::invoice('hand', mailbox: null, emailDate: null, createdAt: ApInvoiceFixtures::utc('2026-10-02 12:00:00'), invoiceDate: '2020-01-01'));
        $this->world->stored(ApInvoiceFixtures::invoice('handOutside', mailbox: null, emailDate: null, createdAt: ApInvoiceFixtures::utc('2026-12-02 12:00:00'), invoiceDate: '2026-10-02'));

        $in = $this->listed(['fromDate' => '2026-10-01', 'toDate' => '2026-10-03', 'sort' => 'created_date']);
        sort($in);
        self::assertSame(['edgeAfter', 'edgeBefore', 'hand', 'inside'], $in);
        $fromOnly = $this->listed(['fromDate' => '2026-10-01']);
        self::assertContains('after', $fromOnly, 'open ended on the other side');
        self::assertNotContains('before', $fromOnly);
        self::assertContains('before', $this->listed(['toDate' => '2026-10-03']));
        self::assertNotContains('after', $this->listed(['toDate' => '2026-10-03']));
    }

    public function test_the_period_is_applied_before_the_row_limit(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('old', emailDate: ApInvoiceFixtures::utc('2026-08-15 00:00:00'), createdAt: ApInvoiceFixtures::utc('2026-08-15 00:00:00')));
        for ($i = 0; $i < 3; ++$i) {
            $this->world->stored(ApInvoiceFixtures::invoice('new' . $i, emailDate: ApInvoiceFixtures::utc('2026-10-08 00:00:00'), createdAt: ApInvoiceFixtures::utc('2026-10-08 0' . $i . ':00:00')));
        }

        // The newest three are not in August; the limit counts August's rows only.
        self::assertSame(['old'], $this->listed(['fromDate' => '2026-08-14', 'toDate' => '2026-08-16', 'limit' => '2']));
    }

    public function test_a_date_that_is_not_yyyy_mm_dd_is_refused_with_the_sources_words(): void
    {
        foreach (['fromDate' => ['10/01/2026', '20261001', '2026-W40-1', '2026-02-30', 'tomorrow', '2026-10-01T00:00:00'], 'toDate' => ['2026-13-01']] as $param => $values) {
            foreach ($values as $value) {
                self::refused(ApInvalid::class, 'Dates must use YYYY-MM-DD', fn () => $this->world->service->list($this->world->actor(), [$param => $value]));
            }
        }
        // An empty value is none, and a from after the to is an empty answer, not an error.
        self::assertSame([], $this->listed(['fromDate' => '2026-10-09', 'toDate' => '2026-10-01']));
        self::assertSame([], $this->listed(['fromDate' => '', 'toDate' => '']));
    }

    public function test_a_scoped_person_is_scoped_before_the_limit_and_sees_hand_captures(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('mine', mailbox: 'Christine@Example.test', createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('hand', mailbox: null, emailDate: null, createdAt: ApInvoiceFixtures::utc('2026-10-02 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('blank', mailbox: '  ', createdAt: ApInvoiceFixtures::utc('2026-10-03 00:00:00')));
        for ($i = 0; $i < 3; ++$i) {
            $this->world->stored(ApInvoiceFixtures::invoice('theirs' . $i, mailbox: 'ap@example.test', createdAt: ApInvoiceFixtures::utc('2026-10-06 0' . $i . ':00:00')));
        }
        $scoped = ApInvoiceFixtures::actor(['christine@example.test']);

        // The newest three belong to another mailbox; with a limit of 2 the person still gets their own two newest.
        self::assertSame(['blank', 'hand'], $this->listed(['limit' => '2'], $scoped));
        self::assertSame(['blank', 'hand', 'mine'], $this->listed([], $scoped));
        self::assertCount(6, $this->listed());
    }

    public function test_one_invoice_is_a_404_when_missing_or_out_of_scope_and_a_hand_capture_is_always_visible(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('theirs', mailbox: 'ap@example.test'));
        $this->world->stored(ApInvoiceFixtures::invoice('hand', mailbox: null));
        $this->world->stored(ApInvoiceFixtures::invoice('binned', deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00'), deletedBy: 'dee@example.test'));
        $scoped = ApInvoiceFixtures::actor(['christine@example.test']);

        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->get($scoped, 'theirs'));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->get($scoped, 'ghost'));
        self::assertSame('hand', $this->world->service->get($scoped, 'hand')['id']);
        self::assertSame('theirs', $this->world->service->get($this->world->actor(), 'theirs')['id']);
        // The Bin's detail page opens an invoice that is in the Bin.
        self::assertSame('dee@example.test', $this->world->service->get($this->world->actor(), 'binned')['deleted_by']);
    }

    // ---- Overdue is derived on read and never stored -----------------------------------------------------

    public function test_overdue_is_derived_from_the_due_date_and_never_stored(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('past', dueDate: '2026-10-08', status: 'In Progress'));
        $this->world->stored(ApInvoiceFixtures::invoice('pastReview', dueDate: '2026-09-01', status: 'Needs Review'));
        $this->world->stored(ApInvoiceFixtures::invoice('today', dueDate: '2026-10-09', status: 'In Progress'));
        $this->world->stored(ApInvoiceFixtures::invoice('later', dueDate: '2026-10-10', status: 'In Progress'));
        $this->world->stored(ApInvoiceFixtures::invoice('none', dueDate: null, status: 'Needs Review'));
        $this->world->stored(ApInvoiceFixtures::invoice('done', dueDate: '2026-01-01', status: 'Completed'));
        $this->world->stored(ApInvoiceFixtures::invoice('exported', dueDate: '2026-01-01', status: 'Exported'));
        $this->world->stored(ApInvoiceFixtures::invoice('binned', dueDate: '2026-01-01', status: 'In Progress', deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00')));

        $status = static fn (array $rows): array => array_column($rows, 'status', 'id');
        $shown = $status($this->world->service->list($this->world->actor(), ['limit' => '1000']));
        self::assertSame('Overdue', $shown['past']);
        self::assertSame('Overdue', $shown['pastReview']);
        self::assertSame('In Progress', $shown['today'], 'due today is not overdue');
        self::assertSame('In Progress', $shown['later']);
        self::assertSame('Needs Review', $shown['none']);
        self::assertSame('Completed', $shown['done']);
        self::assertSame('Exported', $shown['exported']);
        self::assertSame('In Progress', $status($this->world->service->list($this->world->actor(), ['deleted' => '1']))['binned'], 'a binned invoice shows its stored status');
        // Nothing was written: the stored status is what it was.
        self::assertSame('In Progress', $this->world->invoices->find('past')?->status);
        self::assertSame('Needs Review', $this->world->invoices->find('pastReview')?->status);
    }

    public function test_the_status_filter_and_sort_use_the_derived_status(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('overdue', dueDate: '2026-10-01', status: 'In Progress'));
        $this->world->stored(ApInvoiceFixtures::invoice('open', dueDate: '2026-12-01', status: 'In Progress'));
        $this->world->stored(ApInvoiceFixtures::invoice('review', dueDate: '2026-12-01', status: 'Needs Review'));

        self::assertSame(['overdue'], $this->listed(['status' => 'Overdue']));
        self::assertSame(['open'], $this->listed(['status' => 'In Progress']), 'In Progress excludes the overdue ones');
        self::assertSame(['review'], $this->listed(['status' => 'Needs Review']));
        self::assertSame([], $this->listed(['status' => 'Nonsense']));
    }

    public function test_a_status_sort_orders_by_what_the_row_shows(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', dueDate: '2026-10-01', status: 'In Progress'));
        $this->world->stored(ApInvoiceFixtures::invoice('b', dueDate: '2026-12-01', status: 'Needs Review'));
        $this->world->stored(ApInvoiceFixtures::invoice('c', dueDate: '2026-12-01', status: 'Completed'));

        // Completed, Needs Review, Overdue (a is stored In Progress but shown Overdue).
        self::assertSame(['c', 'b', 'a'], $this->listed(['sort' => 'status']));
    }

    // ---- manual capture ---------------------------------------------------------------------------------------

    public function test_a_hand_typed_capture_is_not_graded_and_starts_in_needs_review(): void
    {
        $row = $this->created(['invoice_no' => 'A-1', 'vendor' => 'Smith', 'total_amount' => 12.5, 'is_revised' => false]);

        self::assertSame('Needs Review', $row['status']);
        self::assertNull($row['review_reason']);
        self::assertNull($row['extraction_confidence']);
        self::assertSame(12.5, $row['total_amount']);
        self::assertNull($row['mailbox'], 'a hand capture has no mailbox');
        self::assertFalse($row['has_file']);
        self::assertSame(32, strlen(self::asString($row['id'])));
        self::assertSame('Needs Review', $this->created([])['status'], 'an empty body still creates an invoice');
    }

    public function test_a_deliberate_status_is_kept_and_a_typed_status_is_not_overridden(): void
    {
        self::assertSame('Exported', $this->created(['vendor' => 'x', 'status' => 'Exported'])['status']);
        self::assertSame('In Progress', $this->created(['vendor' => 'x', 'status' => 'In Progress'])['status']);

        $graded = $this->created(['invoice_no' => '', 'vendor' => 'x', 'extraction_confidence' => 0.99, 'status' => 'Exported']);
        self::assertSame('Exported', $graded['status'], 'a status the caller sent is kept');
        self::assertSame('Could not read the invoice number, total', $graded['review_reason'], 'but the reason is still recorded');
    }

    public function test_a_model_read_capture_is_graded_with_the_sources_reasons(): void
    {
        $clean = $this->created(['invoice_no' => 'A-1', 'vendor' => 'Smith', 'net_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110, 'extraction_confidence' => 0.95]);
        self::assertSame('In Progress', $clean['status']);
        self::assertNull($clean['review_reason']);
        self::assertSame(0.95, $clean['extraction_confidence']);

        $low = $this->created(['invoice_no' => 'A-1', 'vendor' => 'Smith', 'total_amount' => 110, 'extraction_confidence' => 0.41]);
        self::assertSame('Needs Review', $low['status']);
        self::assertSame('Low extraction confidence (41%)', $low['review_reason']);

        $missing = $this->created(['invoice_no' => 'A-1', 'vendor' => '', 'total_amount' => 0, 'extraction_confidence' => 0.99]);
        self::assertSame('Could not read the vendor, total', $missing['review_reason'], 'zero is not a total');
        self::assertSame('Could not read the invoice number, vendor, total; Low extraction confidence (0%)', $this->created(['extraction_confidence' => null])['review_reason'], 'a null confidence is 0, never certain');

        $sums = $this->created(['invoice_no' => 'A-1', 'vendor' => 'Smith', 'net_amount' => 100, 'tax_amount' => 10, 'total_amount' => 210, 'extraction_confidence' => 0.9]);
        self::assertSame('Amounts do not add up: 100.00 + 10.00 = 110.00, but the total reads 210.00', $sums['review_reason']);
        $big = $this->created(['invoice_no' => 'A-1', 'vendor' => 'Smith', 'net_amount' => 1000, 'tax_amount' => 100.5, 'total_amount' => 2000, 'extraction_confidence' => 0.9]);
        self::assertSame('Amounts do not add up: 1,000.00 + 100.50 = 1,100.50, but the total reads 2,000.00', $big['review_reason']);
    }

    public function test_the_grade_compares_in_whole_cents_and_uses_the_threshold_strictly(): void
    {
        $penny = $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'net_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110.01, 'extraction_confidence' => 0.8]);
        self::assertNull($penny['review_reason'], '110.01 against 110.00 passes, and 0.80 is not below 0.80');
        $twoCents = $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'net_amount' => 0.10, 'tax_amount' => 0.20, 'total_amount' => 0.32, 'extraction_confidence' => 0.9]);
        self::assertNull($twoCents['review_reason'], 'exactly two cents out passes, whatever the float noise');
        $threeCents = $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'net_amount' => 0.10, 'tax_amount' => 0.20, 'total_amount' => 0.33, 'extraction_confidence' => 0.9]);
        self::assertSame('Amounts do not add up: 0.10 + 0.20 = 0.30, but the total reads 0.33', $threeCents['review_reason']);
        $unknown = $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'net_amount' => 100, 'tax_amount' => null, 'total_amount' => 999, 'extraction_confidence' => 0.9]);
        self::assertNull($unknown['review_reason'], 'a missing amount is unknown, not wrong');
        $justBelow = $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'total_amount' => 1, 'extraction_confidence' => 0.795]);
        self::assertSame('Low extraction confidence (80%)', $justBelow['review_reason'], 'the percentage rounds half up');
        $classification = $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'total_amount' => 1, 'classification_confidence' => 0.5]);
        self::assertSame('Low extraction confidence (50%)', $classification['review_reason']);
        self::assertSame(0.5, $classification['extraction_confidence'], 'a body with only the classification confidence is a graded capture');
        self::assertSame(1.0, $this->created(['invoice_no' => 'A', 'vendor' => 'S', 'total_amount' => 1, 'extraction_confidence' => 7])['extraction_confidence'], 'clamped to 1');
    }

    public function test_the_capture_writes_only_the_listed_columns_and_never_the_vendor_match_or_a_person(): void
    {
        $row = $this->created([
            'invoice_no' => 'A-1',
            'approval_status' => 'Approved', 'deleted_at' => '2026-01-01T00:00:00Z', 'deleted_by' => 'x@example.test', 'content_hash' => str_repeat('a', 64),
            'file_id' => 'f', 'mailbox' => 'ap@example.test', 'sender' => 'x@example.test', 'subject' => 's', 'email_id' => 'm', 'attachment_id' => 'z',
            'duplicate_of_invoice_id' => 'other', 'odoo_vendor_id' => 5, 'vendor_ref' => 'R', 'odoo_vendor_name' => 'Odoo Name', 'vendor_match' => 'confirmed',
            'vendor_match_note' => 'n', 'id' => 'chosen', 'created_date' => '2000-01-01', 'sender_name' => 'Someone', 'attachment_url' => '/api/files/x',
        ]);

        self::assertNotSame('chosen', $row['id']);
        self::assertSame('Not requested', $row['approval_status']);
        foreach (['deleted_at', 'deleted_by', 'content_hash', 'file_id', 'mailbox', 'sender', 'subject', 'email_id', 'attachment_id', 'duplicate_of_invoice_id', 'odoo_vendor_id', 'vendor_ref', 'odoo_vendor_name', 'vendor_match', 'vendor_match_note'] as $key) {
            self::assertNull($row[$key], $key);
        }
        self::assertSame('', $row['sender_name']);
        self::assertStringStartsWith('2026-10-09', self::asString($row['created_date']));
        self::assertArrayNotHasKey('attachment_url', $row, 'no address of a file is ever returned');
    }

    public function test_a_capture_checks_status_and_dates_with_the_sources_messages(): void
    {
        self::refused(ApInvalid::class, "Invalid status: 'Overdue'", fn () => $this->created(['status' => 'Overdue']));
        self::refused(ApInvalid::class, "Invalid status: 'Done'", fn () => $this->created(['status' => 'Done']));
        self::refused(ApInvalid::class, "Invalid duplicate_status: 'Maybe'", fn () => $this->created(['duplicate_status' => 'Maybe']));
        self::refused(ApInvalid::class, "invoice_date is not a date: 'Net 30'", fn () => $this->created(['invoice_date' => 'Net 30']));
        self::refused(ApInvalid::class, "due_date is not a date: '31/02/2026'", fn () => $this->created(['due_date' => '31/02/2026']));
        // No status, a null or an empty one means "none sent".
        self::assertSame('Needs Review', $this->created(['status' => null])['status']);
        self::assertSame('Needs Review', $this->created(['status' => ''])['status']);
    }

    public function test_a_capture_stores_dates_as_iso_and_amounts_to_the_cent(): void
    {
        $row = $this->created(['invoice_date' => '01/10/2026', 'due_date' => '3rd August 2026', 'net_amount' => '10.005', 'tax_amount' => 1, 'total_amount' => '11.01']);

        self::assertSame('2026-10-01', $row['invoice_date']);
        self::assertSame('2026-08-03', $row['due_date']);
        self::assertSame(10.01, $row['net_amount'], 'half up to the cent');
        self::assertSame(1.0, $row['tax_amount']);
        self::assertSame(11.01, $row['total_amount']);
        self::assertNull($this->created(['invoice_date' => null, 'due_date' => ''])['invoice_date']);
    }

    public function test_a_capture_checks_what_a_column_cannot_hold(): void
    {
        self::refused(ApInvalid::class, 'vendor must be at most 400 characters', fn () => $this->created(['vendor' => str_repeat('v', 401)]));
        self::refused(ApInvalid::class, 'invoice_no must be at most 100 characters', fn () => $this->created(['invoice_no' => str_repeat('1', 101)]));
        self::refused(ApInvalid::class, 'total_amount must be a number', fn () => $this->created(['total_amount' => 'twelve']));
        self::refused(ApInvalid::class, 'net_amount must be a number', fn () => $this->created(['net_amount' => ['1']]));
        self::refused(ApInvalid::class, 'vendor must be text', fn () => $this->created(['vendor' => ['a']]));
        self::refused(ApInvalid::class, 'total_amount is too large', fn () => $this->created(['total_amount' => 1e20]));
        self::assertSame(str_repeat('v', 400), $this->created(['vendor' => str_repeat('v', 400)])['vendor']);
    }

    public function test_a_capture_with_an_upload_takes_the_file_its_name_and_its_hash(): void
    {
        $bytes = '%PDF-1.4 invented invoice';
        $upload = $this->world->attachments->upload($this->world->actor(), 'Smith Invoice 77.pdf', $bytes);
        $row = $this->created(['upload_id' => $upload['upload_id'], 'attachment' => 'a different name.pdf', 'invoice_no' => 'A-1']);

        self::assertSame('Smith Invoice 77.pdf', $row['attachment'], 'the server value wins');
        self::assertTrue($row['has_file']);
        self::assertSame($upload['upload_id'], $row['file_id']);
        self::assertSame(hash('sha256', $bytes), $row['content_hash'], 'the upload is hashed so a later scan sees an exact duplicate (A2)');
        self::assertArrayNotHasKey('attachment_url', $row);
        $stored = $this->world->files->forRecord(Module::APP_AP, 'invoice', self::asString($row['id']));
        self::assertCount(1, $stored, 'the upload is now attached to the invoice, record type invoice');
        // A hand capture does not refuse a duplicate hash (A2): the same bytes uploaded again create a second invoice.
        $again = $this->world->attachments->upload($this->world->actor(), 'again.pdf', $bytes);
        self::assertNotSame($row['id'], $this->created(['upload_id' => $again['upload_id']])['id']);
    }

    public function test_an_upload_id_that_is_unknown_used_or_someone_elses_is_refused(): void
    {
        $upload = $this->world->attachments->upload($this->world->actor(), 'a.pdf', '%PDF');
        $this->created(['upload_id' => $upload['upload_id']]);
        $message = 'The upload was not found, or it is already on an invoice';

        self::refused(ApInvalid::class, $message, fn () => $this->created(['upload_id' => $upload['upload_id']]));
        self::refused(ApInvalid::class, $message, fn () => $this->created(['upload_id' => 'nope']));
        $other = $this->world->attachments->upload(ApInvoiceFixtures::actor(userId: 99), 'b.pdf', '%PDF');
        self::refused(ApInvalid::class, $message, fn () => $this->created(['upload_id' => $other['upload_id']]));
        self::refused(ApInvalid::class, 'upload_id must be text', fn () => $this->created(['upload_id' => 12]));
        self::assertCount(1, $this->listed(), 'a refused capture creates nothing');
    }

    // ---- PATCH ------------------------------------------------------------------------------------------------

    public function test_a_patch_changes_only_the_keys_it_carries_and_bumps_updated_date(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', notes: 'keep me'));
        $row = $this->world->service->update($this->world->actor(), 'a', ['vendor' => 'New Vendor', 'is_revised' => true, 'total_amount' => '120.456', 'invoice_date' => '2/10/26']);

        self::assertSame('New Vendor', $row['vendor']);
        self::assertTrue($row['is_revised']);
        self::assertSame(120.46, $row['total_amount']);
        self::assertSame('2026-10-02', $row['invoice_date']);
        self::assertSame('keep me', $row['notes']);
        self::assertSame('INV-a', $row['invoice_no']);
        self::assertStringStartsWith('2026-10-09T01:02:03', self::asString($row['updated_date']));
        // "Checked, release for processing": the register's button.
        $released = $this->world->service->update($this->world->actor(), 'a', ['status' => 'In Progress', 'review_reason' => null]);
        self::assertNull($released['review_reason']);
    }

    public function test_a_patch_cannot_write_the_vendor_match_the_approval_the_bin_the_hash_the_file_or_a_person(): void
    {
        $before = $this->world->stored(ApInvoiceFixtures::invoice('a', contentHash: str_repeat('b', 64), fileId: 'file-1', approvalStatus: 'Pending approval', vendorMatch: 'matched', odooVendorId: 4, vendorRef: 'R4', odooVendorName: 'Odoo Four', vendorMatchNote: 'auto'));
        $row = $this->world->service->update($this->world->actor(), 'a', [
            'notes' => 'changed',
            'approval_status' => 'Approved', 'deleted_at' => '2026-01-01T00:00:00Z', 'deleted_by' => 'x', 'content_hash' => str_repeat('c', 64), 'file_id' => 'other',
            'mailbox' => 'elsewhere@example.test', 'sender' => 'x@example.test', 'subject' => 'x', 'email_id' => 'x', 'email_record_id' => 'x', 'attachment_id' => 'x', 'attachment' => 'x.pdf',
            'duplicate_of_invoice_id' => 'x', 'odoo_vendor_id' => 99, 'vendor_ref' => 'X', 'odoo_vendor_name' => 'X', 'vendor_match' => 'confirmed', 'vendor_match_note' => 'x',
            'id' => 'z', 'created_date' => '2000-01-01', 'extraction_confidence' => 0.1, 'sender_name' => 'Y',
        ]);

        self::assertSame('changed', $row['notes']);
        $after = $this->world->invoices->find('a');
        self::assertNotNull($after);
        self::assertEquals($before->with(notes: 'changed', updatedAt: $after->updatedAt), $after, 'nothing but the notes and the update time moved');
    }

    public function test_a_patch_refuses_a_status_or_duplicate_status_with_the_sources_messages(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a'));
        $actor = $this->world->actor();

        self::refused(ApInvalid::class, "Invalid status: 'Overdue'", fn () => $this->world->service->update($actor, 'a', ['status' => 'Overdue']));
        self::refused(ApInvalid::class, "Invalid status: 'Approved'", fn () => $this->world->service->update($actor, 'a', ['status' => 'Approved']));
        self::refused(ApInvalid::class, 'Invalid status: None', fn () => $this->world->service->update($actor, 'a', ['status' => null]));
        self::refused(ApInvalid::class, "Invalid duplicate_status: 'Maybe'", fn () => $this->world->service->update($actor, 'a', ['duplicate_status' => 'Maybe']));
        self::refused(ApInvalid::class, 'Invalid duplicate_status: None', fn () => $this->world->service->update($actor, 'a', ['duplicate_status' => null]));
        self::refused(ApInvalid::class, "invoice_date is not a date: 'Net 30'", fn () => $this->world->service->update($actor, 'a', ['invoice_date' => 'Net 30']));
        self::refused(ApInvalid::class, "due_date is not a date: '31/02/2026'", fn () => $this->world->service->update($actor, 'a', ['due_date' => '31/02/2026']));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->update($actor, 'ghost', ['notes' => 'x']));
        self::assertSame('In Progress', $this->world->invoices->find('a')?->status, 'a refused patch changes nothing');
    }

    public function test_setting_needs_review_on_a_past_due_invoice_reads_back_overdue(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', dueDate: '2026-09-01', status: 'Completed'));
        $row = $this->world->service->update($this->world->actor(), 'a', ['status' => 'Needs Review']);

        self::assertSame('Overdue', $row['status']);
        self::assertSame('Needs Review', $this->world->invoices->find('a')?->status);
        self::assertSame('Completed', $this->world->service->update($this->world->actor(), 'a', ['status' => 'Completed'])['status']);
    }

    public function test_a_patch_out_of_scope_is_a_404_and_an_empty_one_changes_nothing(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('theirs', mailbox: 'ap@example.test'));
        $scoped = ApInvoiceFixtures::actor(['christine@example.test']);

        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->update($scoped, 'theirs', ['notes' => 'x']));
        $before = $this->world->invoices->find('theirs');
        $row = $this->world->service->update($this->world->actor(), 'theirs', ['nonsense' => 1]);
        self::assertSame('theirs', $row['id']);
        self::assertEquals($before, $this->world->invoices->find('theirs'));
        self::assertSame([], $this->world->audit->all());
    }

    public function test_clearing_a_duplicate_writes_the_override_event_and_editing_never_rechecks_it(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', duplicateStatus: 'Potential', duplicateOfInvoiceId: 'earlier', duplicateReason: ApInvoice::BUSINESS_DUPLICATE_REASON));
        $actor = $this->world->actor();

        $row = $this->world->service->update($actor, 'a', ['duplicate_status' => 'Cleared by user', 'duplicate_reason' => 'Reviewed and marked not a duplicate']);
        self::assertSame('Cleared by user', $row['duplicate_status']);
        self::assertSame('earlier', $row['duplicate_of_invoice_id'], 'the link is left in place');
        $events = $this->world->audit->forEntity('invoice', 'a');
        self::assertCount(1, $events);
        self::assertSame('duplicate_override', $events[0]->action);
        self::assertSame(['reason' => 'Reviewed and marked not a duplicate'], $events[0]->details);
        self::assertSame(7, $events[0]->actorUserId);
        self::assertSame('Dee Preparer', $events[0]->actorName);

        // Again, with no reason: the written one; the event is written every time, even when it already was cleared.
        $this->world->service->update($actor, 'a', ['duplicate_status' => 'Cleared by user']);
        self::assertSame(['reason' => 'Marked not a duplicate'], $this->world->audit->forEntity('invoice', 'a')[1]->details);
        // Clear and Potential are accepted and write no event; editing the number, vendor or total does not recompute anything.
        $this->world->service->update($actor, 'a', ['duplicate_status' => 'Potential']);
        $this->world->service->update($actor, 'a', ['duplicate_status' => 'Clear', 'invoice_no' => 'CHANGED', 'vendor' => 'Other', 'total_amount' => 1]);
        self::assertCount(2, $this->world->audit->forEntity('invoice', 'a'));
        self::assertSame('Clear', $this->world->invoices->find('a')?->duplicateStatus);
    }

    // ---- Bin, restore, permanent delete ------------------------------------------------------------------------

    public function test_delete_moves_to_the_bin_once_and_names_who(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', invoiceNo: 'A-1', vendor: 'Smith'));
        $actor = $this->world->actor();

        $answer = $this->world->service->delete($actor, 'a', false);
        self::assertTrue($answer['ok']);
        self::assertTrue($answer['in_bin']);
        self::assertIsArray($answer['invoice']);
        self::assertSame('dee@example.test', $answer['invoice']['deleted_by'], 'the address, lower-case');
        self::assertStringStartsWith('2026-10-09T01:02:03', self::asString($answer['invoice']['deleted_at']));
        $stored = $this->world->invoices->find('a');
        self::assertSame(7, $stored?->deletedByUserId);
        self::assertSame(['a'], $this->listed(['deleted' => '1']));
        self::assertSame([], $this->listed());

        $events = $this->world->audit->forEntity('invoice', 'a');
        self::assertSame(['invoice_binned'], array_map(static fn (ApAuditEvent $e): string => $e->action, $events));
        self::assertSame(['invoice_no' => 'A-1', 'vendor' => 'Smith'], $events[0]->details);

        // Already in the Bin: nothing changes, no second event, the same answer.
        $this->world->clock->advance('+1 hour');
        $again = $this->world->service->delete($actor, 'a', false);
        self::assertTrue($again['in_bin']);
        self::assertSame($answer['invoice'], $again['invoice']);
        self::assertCount(1, $this->world->audit->forEntity('invoice', 'a'));
    }

    public function test_deleting_a_missing_id_is_ok_and_out_of_scope_is_a_404(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('theirs', mailbox: 'ap@example.test'));
        $scoped = ApInvoiceFixtures::actor(['christine@example.test']);

        self::assertSame(['ok' => true], $this->world->service->delete($this->world->actor(), 'ghost', false));
        self::assertSame(['ok' => true], $this->world->service->delete($this->world->actor(), 'ghost', true));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->delete($scoped, 'theirs', false));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->delete(ApInvoiceFixtures::actor(['christine@example.test']), 'theirs', true));
        self::assertNull($this->world->invoices->find('theirs')?->deletedAt);
    }

    public function test_restore_clears_the_bin_marks_audits_every_time_and_works_on_a_live_invoice(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', invoiceNo: 'A-1'));
        $actor = $this->world->actor();
        $this->world->service->delete($actor, 'a', false);

        $row = $this->world->service->restore($actor, 'a');
        self::assertNull($row['deleted_at']);
        self::assertNull($row['deleted_by']);
        self::assertNull($this->world->invoices->find('a')?->deletedByUserId);
        // Not in the Bin any more, and the source audited anyway.
        $this->world->service->restore($actor, 'a');
        $events = $this->world->audit->forEntity('invoice', 'a');
        self::assertSame(['invoice_binned', 'invoice_restored', 'invoice_restored'], array_map(static fn (ApAuditEvent $e): string => $e->action, $events));
        self::assertSame(['invoice_no' => 'A-1'], $events[1]->details);

        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->restore($actor, 'ghost'));
        $this->world->stored(ApInvoiceFixtures::invoice('theirs', mailbox: 'ap@example.test'));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->service->restore(ApInvoiceFixtures::actor(['christine@example.test']), 'theirs'));
    }

    public function test_permanent_delete_is_only_from_the_bin_and_needs_the_delete_permission(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a'));
        $actor = $this->world->actor();

        self::refused(ApConflict::class, 'Move the invoice to the Bin before deleting it permanently', fn () => $this->world->service->delete($actor, 'a', true));
        self::assertNotNull($this->world->invoices->find('a'), 'nothing changed');
        self::assertSame([], $this->world->audit->all());

        $this->world->service->delete($actor, 'a', false);
        $writer = ApInvoiceFixtures::actor(null, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_INVOICES_WRITE]);
        self::refused(ApForbidden::class, 'You do not have permission to delete invoices permanently', fn () => $this->world->service->delete($writer, 'a', true));
        self::refused(ApForbidden::class, 'You do not have permission to delete invoices permanently', fn () => $this->world->service->delete($writer, 'ghost', true));
        self::assertNotNull($this->world->invoices->find('a'));

        $answer = $this->world->service->delete($actor, 'a', true);
        self::assertSame(['ok' => true, 'in_bin' => false], $answer);
        self::assertNull($this->world->invoices->find('a'));
        self::assertSame(['invoice_binned', 'invoice_deleted_permanently'], array_map(static fn (ApAuditEvent $e): string => $e->action, $this->world->audit->forEntity('invoice', 'a')));
        self::assertSame(['a'], $this->world->invoices->purged);
    }

    public function test_a_permanent_delete_writes_the_scan_tombstone_the_attachment_id_preferred(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('withId', emailId: 'AAMk-1', attachmentId: 'ATT-1', attachment: 'Invoice.pdf'));
        $this->world->stored(ApInvoiceFixtures::invoice('nameOnly', emailId: 'AAMk-2', attachmentId: null, attachment: 'Old Invoice.pdf'));
        $this->world->stored(ApInvoiceFixtures::invoice('hand', emailId: null, mailbox: null, attachmentId: null, attachment: 'Hand.pdf'));
        $this->world->stored(ApInvoiceFixtures::invoice('longId', emailId: 'AAMk-3', attachmentId: str_repeat('x', 300), attachment: 'Long.pdf'));
        $actor = $this->world->actor();
        foreach (['withId', 'nameOnly', 'hand', 'longId'] as $id) {
            $this->world->service->delete($actor, $id, false);
            $this->world->service->delete($actor, $id, true);
        }

        self::assertSame([['AAMk-1', 'ATT-1'], ['AAMk-2', 'Old Invoice.pdf'], ['AAMk-3', 'Long.pdf']], $this->world->invoices->skips(), 'a hand capture writes none; an id wider than the column is not cut, the file name stands in');
        self::assertTrue($this->world->invoices->scanSkipped('AAMk-1', 'ATT-1', 'whatever.pdf'));
        self::assertTrue($this->world->invoices->scanSkipped('AAMk-2', null, 'Old Invoice.pdf'));
        self::assertFalse($this->world->invoices->scanSkipped('AAMk-1', 'ATT-2', 'other.pdf'));
        self::assertFalse($this->world->invoices->scanSkipped('AAMk-9', 'ATT-1', 'Invoice.pdf'));
    }

    public function test_a_permanent_delete_removes_the_file_and_other_invoices_lose_their_link_to_it(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('first'), '%PDF first');
        $second = $this->world->stored(ApInvoiceFixtures::invoice('second', duplicateOfInvoiceId: 'first', duplicateStatus: 'Potential'), '%PDF second');
        $store = $this->world->fileStore;
        self::assertInstanceOf(InMemoryFinanceAttachmentStore::class, $store);
        self::assertCount(2, $store->blobPaths());
        $actor = $this->world->actor();

        $this->world->service->delete($actor, 'first', false);
        $answer = $this->world->service->delete($actor, 'first', true);

        self::assertSame(['ok' => true, 'in_bin' => false], $answer);
        self::assertCount(1, $store->blobPaths(), 'the bytes of the deleted invoice are gone, the other invoice keeps its own');
        self::assertNotNull($second->fileId);
        self::assertNotNull($store->find(Module::APP_AP, $second->fileId));
        self::assertNull($this->world->invoices->find('second')?->duplicateOfInvoiceId);
    }

    public function test_a_file_that_cannot_be_removed_does_not_undo_the_delete_and_is_reported(): void
    {
        $world = new ApInvoiceWorld(new FailingDeleteAttachmentStore());
        $world->stored(ApInvoiceFixtures::invoice('a'), '%PDF');
        $actor = $world->actor();
        $world->service->delete($actor, 'a', false);

        $answer = $world->service->delete($actor, 'a', true);

        self::assertSame(['ok' => true, 'in_bin' => false, 'files_orphaned' => true], $answer);
        self::assertNull($world->invoices->find('a'));
        self::assertCount(1, $world->platformAudit->events);
        $event = $world->platformAudit->events[0];
        self::assertSame('ap.invoice.files_orphaned', $event->action);
        self::assertSame('a', $event->entityId);
        self::assertSame('AP', $event->application);
        self::assertSame(\RuntimeException::class, $event->details['error']);
    }

    // ---- for the scan --------------------------------------------------------------------------------------------

    public function test_the_exact_file_rule_finds_the_oldest_invoice_in_any_state(): void
    {
        $hash = str_repeat('a', 64);
        $this->world->stored(ApInvoiceFixtures::invoice('later', contentHash: $hash, createdAt: ApInvoiceFixtures::utc('2026-10-02 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('earlier', contentHash: $hash, createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00'), deletedAt: ApInvoiceFixtures::utc('2026-10-03 00:00:00')));

        self::assertSame('earlier', $this->world->service->findExactDuplicate($hash)?->id, 'a binned invoice still counts');
        self::assertSame('earlier', $this->world->service->findExactDuplicate(strtoupper($hash))?->id);
        self::assertNull($this->world->service->findExactDuplicate(str_repeat('b', 64)));
        self::assertNull($this->world->service->findExactDuplicate(''));
        $this->world->service->delete($this->world->actor(), 'earlier', true);
        self::assertSame('later', $this->world->service->findExactDuplicate($hash)?->id, 'a permanently deleted invoice no longer counts');
    }

    public function test_the_business_rule_matches_number_vendor_currency_and_total_to_the_cent(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('old', invoiceNo: 'INV-77', vendor: 'Acme Freight', currency: 'AUD', totalAmount: 110.00, createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00')));
        $this->world->stored(ApInvoiceFixtures::invoice('usd', invoiceNo: 'INV-78', vendor: 'Acme Freight', currency: 'USD', totalAmount: 50.00));
        $this->world->stored(ApInvoiceFixtures::invoice('blank', invoiceNo: 'INV-79', vendor: 'Blank Currency Ltd', currency: '', totalAmount: 20.00));
        $service = $this->world->service;

        self::assertSame('old', $service->findBusinessDuplicate(' inv-77 ', 'ACME FREIGHT', 'aud', 110.0)?->id, 'trimmed, any case');
        self::assertSame('old', $service->findBusinessDuplicate('INV-77', 'Acme Freight', null, 110.0)?->id, 'no currency reads AUD');
        self::assertSame('blank', $service->findBusinessDuplicate('INV-79', 'Blank Currency Ltd', 'AUD', 20.0)?->id, 'an invoice stored with no currency reads AUD too');
        self::assertNull($service->findBusinessDuplicate('INV-77', 'Acme Freight', 'AUD', 110.01), 'a cent out is not the same document');
        self::assertNull($service->findBusinessDuplicate('INV-77', 'Acme Freight', 'USD', 110.0), 'another currency');
        self::assertSame('usd', $service->findBusinessDuplicate('INV-78', 'Acme Freight', 'USD', 50.0)?->id);
        self::assertNull($service->findBusinessDuplicate('', 'Acme Freight', 'AUD', 110.0), 'no number, no duplicate');
        self::assertNull($service->findBusinessDuplicate('INV-77', '  ', 'AUD', 110.0), 'no vendor, no duplicate');
        self::assertNull($service->findBusinessDuplicate('INV-77', 'Acme Freight', 'AUD', null), 'no total, no duplicate');
        self::assertSame('old', $service->findBusinessDuplicate('INV-77', 'Acme Freight', 'AUD', 110.004)?->id, 'rounded to the cent first');
    }

    public function test_the_business_rule_sees_binned_and_cleared_invoices_and_the_oldest_wins(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('b', invoiceNo: 'X', vendor: 'V', totalAmount: 5.0, createdAt: ApInvoiceFixtures::utc('2026-10-02 00:00:00'), duplicateStatus: 'Cleared by user'));
        $this->world->stored(ApInvoiceFixtures::invoice('a', invoiceNo: 'X', vendor: 'V', totalAmount: 5.0, createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00'), deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00')));

        $first = $this->world->service->findBusinessDuplicate('X', 'V', 'AUD', 5.0);
        self::assertNotNull($first);
        self::assertSame('a', $first->id);
        $this->world->service->delete($this->world->actor(), 'a', true);
        $second = $this->world->service->findBusinessDuplicate('X', 'V', 'AUD', 5.0);
        self::assertNotNull($second);
        self::assertSame('b', $second->id);
    }

    private static function asString(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
