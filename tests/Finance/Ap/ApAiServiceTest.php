<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\ApDraftEmail;
use TriCab\Core\Finance\Ap\ApDraftInvoice;
use TriCab\Core\Finance\Ap\ApExtractionCompany;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/** Tri-AP's model calls (T6.8) against a scripted client: data only, typed results, the review flag, failures typed and logged. */
final class ApAiServiceTest extends TestCase
{
    private ScriptedApAiClient $client;

    /** @var list<string> */
    private array $log = [];

    /** @var list<ApExtractionCompany> */
    private array $companies;

    protected function setUp(): void
    {
        $this->client = new ScriptedApAiClient();
        $this->log = [];
        $this->companies = [
            new ApExtractionCompany('TriCab (Australia) Pty Ltd', 'AU', 'Sydney depot', 'Cable Co Pty Ltd'),
            new ApExtractionCompany('TriCab NZ Limited', 'NZ'),
            new ApExtractionCompany('Old Entity', null, '', '', false),
        ];
    }

    private function service(bool $suggest = true, float $threshold = 0.80): ApAiService
    {
        return new ApAiService($this->client, $suggest, $threshold, function (string $line): void {
            $this->log[] = $line;
        });
    }

    public function test_classify_sends_data_only_the_active_companies_and_the_file_in_base64(): void
    {
        $out = $this->service()->classify('Invoice 42', 'ap@acme.example.test', 'inv.pdf', 'application/pdf', '%PDF-bytes', [...$this->companies, new ApExtractionCompany('   ')]);

        self::assertSame(1, $this->client->count('ap_classify_extract'));
        $call = $this->client->calls[0];
        self::assertArrayNotHasKey('prompt', $call['data']);
        self::assertSame('Invoice 42', $call['data']['subject']);
        self::assertSame('ap@acme.example.test', $call['data']['sender']);
        self::assertSame(
            [
                ['name' => 'TriCab (Australia) Pty Ltd', 'sites' => 'Sydney depot', 'former_names' => 'Cable Co Pty Ltd'],
                ['name' => 'TriCab NZ Limited', 'sites' => '', 'former_names' => ''],
            ],
            $call['data']['companies'],
            'only active, named companies reach the prompt',
        );
        self::assertSame(['name' => 'inv.pdf', 'content_type' => 'application/pdf', 'data' => base64_encode('%PDF-bytes')], $call['data']['file']);

        self::assertTrue($out->isInvoice);
        self::assertSame('Tax invoice for cable.', $out->reason);
        self::assertSame(0.91, $out->confidence);
        self::assertSame('Invoice', $out->label());
        self::assertSame('INV-42', $out->extraction->invoiceNo);
        self::assertSame('TriCab (Australia) Pty Ltd', $out->extraction->company);
        self::assertSame(110.0, $out->extraction->totalAmount);
        self::assertSame(0.91, $out->extraction->extractionConfidence, 'the classification confidence stands in when the extraction one is absent');
        self::assertNull($out->extraction->reviewReason);
        self::assertSame('In Progress', $out->extraction->status());
        self::assertSame('In Progress', $out->status());
    }

    public function test_a_non_invoice_is_other_and_a_forced_capture_gets_the_fixed_reason(): void
    {
        $this->client->answers['ap_classify_extract'] = [
            ...ScriptedApAiClient::invoiceFields(), 'extraction_confidence' => null,
            'is_invoice' => false, 'classification_reason' => 'A statement.', 'classification_confidence' => 0.97,
        ];

        $out = $this->service()->classify('Statement', 'x@y.test', 's.pdf', 'application/pdf', 'b', $this->companies);

        self::assertFalse($out->isInvoice);
        self::assertSame('Other', $out->label());
        self::assertSame('A statement.', $out->reason);
        self::assertNull($out->reviewReason(false));
        self::assertSame(
            'Captured by hand from the AP Mailbox; the AI did not read this file as an invoice',
            $out->reviewReason(true),
        );
        self::assertSame('Needs Review', $out->status(true));
    }

    public function test_a_result_below_the_threshold_is_flagged_for_review_and_one_at_it_is_not(): void
    {
        $below = [...ScriptedApAiClient::invoiceFields(), 'extraction_confidence' => 0.79];
        $at = [...ScriptedApAiClient::invoiceFields(), 'extraction_confidence' => 0.80];

        $this->client->answers['ap_extract'] = $below;
        $flagged = $this->service()->extract('a.pdf', 'application/pdf', 'b', $this->companies);
        $this->client->answers['ap_extract'] = $at;
        $clear = $this->service()->extract('a.pdf', 'application/pdf', 'b', $this->companies);

        self::assertSame('Low extraction confidence (79%)', $flagged->reviewReason);
        self::assertSame('Needs Review', $flagged->status());
        self::assertTrue($flagged->needsReview());
        self::assertNull($clear->reviewReason);
        self::assertSame('In Progress', $clear->status());
        // The threshold is a constructor argument (INVOICE_CONFIDENCE_THRESHOLD): 0.95 flags the 0.93 default read.
        $this->client->answers['ap_extract'] = ScriptedApAiClient::invoiceFields();
        self::assertSame('Low extraction confidence (93%)', $this->service(true, 0.95)->extract('a.pdf', 'application/pdf', 'b', $this->companies)->reviewReason);
    }

    public function test_extract_sends_no_prompt_and_answers_the_fields_the_capture_dialog_reads(): void
    {
        $out = $this->service()->extract('scan.png', 'image/png', 'png-bytes', $this->companies);

        $data = $this->client->calls[0]['data'];
        self::assertSame('ap_extract', $this->client->calls[0]['purpose']);
        self::assertSame(['companies', 'file'], array_keys($data), 'the browser\'s own prompt is not carried');
        self::assertSame(
            [
                'invoice_no' => 'INV-42', 'vendor' => 'Acme Pty Ltd', 'company' => 'TriCab (Australia) Pty Ltd',
                'vendor_email' => 'ap@acme.example.test', 'tax_id' => '12 345 678 901', 'invoice_date' => '2026-09-01',
                'due_date' => '2026-09-30', 'po_number' => 'PO-7', 'currency' => 'AUD', 'net_amount' => 100.0,
                'tax_amount' => 10.0, 'total_amount' => 110.0, 'extraction_confidence' => 0.93, 'notes' => 'Fine.',
                'review_reason' => null,
            ],
            $out->toArray(),
        );
    }

    public function test_the_company_the_model_wrote_is_checked_against_the_list(): void
    {
        $this->client->answers['ap_extract'] = [...ScriptedApAiClient::invoiceFields(), 'company' => 'TRICAB NZ LIMITED'];
        self::assertSame('TriCab NZ Limited', $this->service()->extract('a.pdf', 'application/pdf', 'b', $this->companies)->company);

        $this->client->answers['ap_extract'] = [...ScriptedApAiClient::invoiceFields(), 'company' => 'TriCab Atlantis Ltd'];
        self::assertNull($this->service()->extract('a.pdf', 'application/pdf', 'b', $this->companies)->company, 'an invented entity is dropped');
    }

    public function test_a_provider_failure_is_logged_without_the_data_and_rethrown_typed(): void
    {
        $this->client->answers['ap_extract'] = FinanceAiFailure::of(FinanceAiFailure::PROVIDER_REJECTED, 'The model provider refused the request (HTTP 402).', 'corr-0123456789abcdef');

        try {
            $this->service()->extract('secret-name.pdf', 'application/pdf', 'SECRET-BYTES', $this->companies);
            self::fail('Expected a failure.');
        } catch (FinanceAiFailure $failure) {
            self::assertSame(FinanceAiFailure::PROVIDER_REJECTED, $failure->failureCode);
            self::assertSame('The model provider refused the request (HTTP 402).', $failure->getMessage());
        }
        self::assertSame(['Tri-AP AI call failed (purpose ap_extract, code provider_rejected, correlation corr-0123456789abcdef)'], $this->log);
        self::assertStringNotContainsString('SECRET', $this->log[0]);

        $this->client->answers['ap_classify_extract'] = FinanceAiFailure::of(FinanceAiFailure::BAD_MODEL_ANSWER, 'The model\'s answer could not be read as the expected result.');
        $this->expectException(FinanceAiFailure::class);
        $this->service()->classify('s', 'f', 'a.pdf', 'application/pdf', 'b', $this->companies);
    }

    public function test_the_vendor_email_draft_sends_the_invoice_and_trimmed_instruction_and_addresses_it(): void
    {
        $invoice = new ApDraftInvoice('Acme Pty Ltd', 'INV-42', '2026-09-01', 'PO-7', 'AUD', 110.5, 'Invoice INV-42', 'ap@acme.example.test', 'sender@acme.example.test');

        $draft = $this->service()->draftVendorEmail($invoice, "  Ask when it will be paid.\n", 'Old draft');

        $call = $this->client->calls[0];
        self::assertSame('ap_vendor_email_draft', $call['purpose']);
        self::assertSame('Ask when it will be paid.', $call['data']['instruction']);
        self::assertSame('Old draft', $call['data']['previous_draft']);
        self::assertSame(
            ['vendor' => 'Acme Pty Ltd', 'invoice_no' => 'INV-42', 'invoice_date' => '2026-09-01', 'po_number' => 'PO-7', 'currency' => 'AUD', 'total_amount' => 110.5, 'subject' => 'Invoice INV-42'],
            $call['data']['invoice'],
        );
        self::assertSame(['subject' => 'Question about INV-42', 'body' => 'When will this be paid?', 'to' => 'ap@acme.example.test'], $draft->toArray());

        $noEmail = new ApDraftInvoice(null, null, null, null, null, null, null, null, 'sender@acme.example.test');
        self::assertSame('sender@acme.example.test', $this->service()->draftVendorEmail($noEmail, 'x')->to);
        $nobody = new ApDraftInvoice(null, null, null, null, null, null, null, '', null);
        self::assertSame('', $this->service()->draftVendorEmail($nobody, 'x')->to);
    }

    public function test_the_reply_draft_sends_the_email_and_addresses_it_to_the_sender(): void
    {
        $email = new ApDraftEmail('Pat Payer', 'pat@acme.example.test', 'Where is our payment?', 'We have not been paid.');

        $draft = $this->service()->draftReply($email, ' Say it is in the next run. ', '');

        $call = $this->client->calls[0];
        self::assertSame('ap_reply_draft', $call['purpose']);
        self::assertSame('Say it is in the next run.', $call['data']['instruction']);
        self::assertSame('', $call['data']['previous_draft']);
        self::assertSame(
            ['sender_name' => 'Pat Payer', 'sender' => 'pat@acme.example.test', 'sender_email' => 'pat@acme.example.test', 'subject' => 'Where is our payment?', 'body_preview' => 'We have not been paid.'],
            $call['data']['email'],
        );
        self::assertSame('pat@acme.example.test', $call['data']['email']['sender'], 'fault R10: the From line carries the address');
        self::assertSame('pat@acme.example.test', $draft->to);
    }

    public function test_a_draft_without_an_instruction_is_refused_before_any_call(): void
    {
        $service = $this->service();
        $invoice = new ApDraftInvoice('V', 'N', null, null, null, null, null, null, null);
        $email = new ApDraftEmail('P', 'p@x.test', 'S', 'B');

        try {
            $service->draftVendorEmail($invoice, "  \n ");
            self::fail('Expected a refusal.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Tell the AI what you need to ask the vendor', $e->getMessage());
        }
        try {
            $service->draftReply($email, '');
            self::fail('Expected a refusal.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Tell the AI what the reply should say', $e->getMessage());
        }
        self::assertSame([], $this->client->calls);
    }

    public function test_the_suggestion_is_one_call_per_email_and_returns_the_body(): void
    {
        $email = new ApDraftEmail('Pat Payer', 'pat@acme.example.test', 'Remittance', 'Paid.');

        self::assertSame('Thanks, we will check.', $this->service()->suggestReply($email));

        self::assertSame(1, $this->client->count('ap_reply_suggest'));
        self::assertSame(['email' => $email->promptData()], $this->client->calls[0]['data']);
    }

    public function test_the_suggestion_switched_off_makes_no_call_and_stores_nothing(): void
    {
        self::assertNull($this->service(false)->suggestReply(new ApDraftEmail('P', 'p@x.test', 'S', 'B')));
        self::assertSame([], $this->client->calls);
    }

    public function test_a_suggestion_that_fails_is_an_empty_string_and_never_fails_the_scan(): void
    {
        $this->client->answers['ap_reply_suggest'] = FinanceAiFailure::of(FinanceAiFailure::TIMEOUT, 'The finance AI service did not answer in time.', 'corr-0123456789abcdef');

        self::assertSame('', $this->service()->suggestReply(new ApDraftEmail('P', 'p@x.test', 'S', 'B')));
        self::assertCount(1, $this->log, 'the failure is still logged');
        self::assertStringContainsString('ap_reply_suggest', $this->log[0]);

        $this->client->answers['ap_reply_suggest'] = ['body' => ''];
        self::assertSame('', $this->service()->suggestReply(new ApDraftEmail('P', 'p@x.test', 'S', 'B')), 'no reply needed is an empty body');
    }

    public function test_the_off_switch_reads_as_the_source_read_it(): void
    {
        self::assertSame('AP_SUGGEST_REPLIES', ApAiService::SUGGEST_REPLIES_SETTING);
        foreach ([null, '', '1', 'true', 'yes', 'on', 'anything'] as $on) {
            self::assertTrue(ApAiService::suggestRepliesEnabled($on), var_export($on, true));
        }
        foreach (['0', 'false', 'FALSE', ' no ', 'No'] as $off) {
            self::assertFalse(ApAiService::suggestRepliesEnabled($off), var_export($off, true));
        }
    }
}
