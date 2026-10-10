<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Ap\Service\ApDraftMailService;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/**
 * The two AI drafts (T6.10) with a scripted model: what the model is given from the invoice or the email (including the
 * address on the reply's From line, fault R10), the answer's shape and subject fallbacks, the 404s, the source's empty-instruction
 * words and a provider failure. Drafting works with sending off. No model is called.
 */
final class ApDraftMailServiceTest extends TestCase
{
    private ApMailWorld $world;

    private ScriptedApAiClient $client;

    private ApDraftMailService $drafts;

    protected function setUp(): void
    {
        $this->world = new ApMailWorld(false);
        $this->client = new ScriptedApAiClient();
        $this->drafts = $this->world->drafts(new ApAiService($this->client, true, 0.8, static function (string $line): void {
        }));
        $this->world->invoice('i1', null, vendor: 'Acme Freight', invoiceNo: 'INV-1', vendorEmail: 'billing@vendor.example.test', sender: 'forwarder@example.test', currency: '', totalAmount: 1234.5, poNumber: 'PO-9', subject: 'Original mail');
        $this->world->email('e1');
    }

    public function test_the_vendor_draft_gives_the_model_the_invoice_and_answers_subject_body_and_to(): void
    {
        $out = $this->drafts->draftVendorEmail($this->world->actor(), 'i1', ['instruction' => '  Ask for a remittance advice  ', 'previous_draft' => 'Old text']);

        self::assertSame(['subject' => 'Question about INV-42', 'body' => 'When will this be paid?', 'to' => 'billing@vendor.example.test'], $out);
        $call = $this->client->calls[0];
        self::assertSame('ap_vendor_email_draft', $call['purpose']);
        self::assertSame('Ask for a remittance advice', $call['data']['instruction']);
        self::assertSame('Old text', $call['data']['previous_draft']);
        self::assertSame(
            ['vendor' => 'Acme Freight', 'invoice_no' => 'INV-1', 'invoice_date' => '2026-10-01', 'po_number' => 'PO-9', 'currency' => 'AUD', 'total_amount' => 1234.5, 'subject' => 'Original mail'],
            $call['data']['invoice'],
            'the currency is AUD when the invoice has none and the total goes in as a number',
        );
    }

    public function test_the_vendor_draft_is_addressed_to_the_sender_when_the_invoice_has_no_vendor_email_and_falls_back_on_the_subject(): void
    {
        $this->world->invoice('i2', null, vendorEmail: '', sender: 'forwarder@example.test', invoiceNo: 'INV-77');
        $this->client->answers['ap_vendor_email_draft'] = ['subject' => '  ', 'body' => 'Hello'];

        $out = $this->drafts->draftVendorEmail($this->world->actor(), 'i2', ['instruction' => 'x']);

        self::assertSame(['subject' => 'Question about invoice INV-77', 'body' => 'Hello', 'to' => 'forwarder@example.test'], $out);
    }

    public function test_the_reply_draft_gives_the_model_the_email_with_the_senders_address_on_the_from_line(): void
    {
        $out = $this->drafts->draftReply($this->world->actor(), 'e1', ['instruction' => 'Say it is in the next run', 'previous_draft' => '']);

        self::assertSame(['subject' => 'Question about INV-42', 'body' => 'When will this be paid?', 'to' => 'Accounts@Acme.example.test'], $out);
        $call = $this->client->calls[0];
        self::assertSame('ap_reply_draft', $call['purpose']);
        self::assertSame(
            ['sender_name' => 'Vera Vendor', 'sender' => 'Accounts@Acme.example.test', 'sender_email' => 'Accounts@Acme.example.test', 'subject' => 'Where is our payment?', 'body_preview' => 'We have not been paid.'],
            $call['data']['email'],
            'fault R10: the source sent no address on the From line',
        );
    }

    public function test_the_reply_subject_falls_back_to_the_original_with_re_unless_it_has_one(): void
    {
        $this->client->answers['ap_reply_draft'] = ['subject' => '', 'body' => 'Hi'];
        self::assertSame('Re: Where is our payment?', $this->drafts->draftReply($this->world->actor(), 'e1', ['instruction' => 'x'])['subject']);

        $this->world->email('e2', subject: 'RE: Already');
        self::assertSame('RE: Already', $this->drafts->draftReply($this->world->actor(), 'e2', ['instruction' => 'x'])['subject']);

        $this->world->email('e3', subject: ' re:lower');
        self::assertSame(' re:lower', $this->drafts->draftReply($this->world->actor(), 'e3', ['instruction' => 'x'])['subject']);
    }

    public function test_an_empty_instruction_is_a_422_with_the_sources_words_after_the_404_and_no_model_call(): void
    {
        foreach ([[], ['instruction' => '   '], ['instruction' => ['x']]] as $payload) {
            try {
                $this->drafts->draftVendorEmail($this->world->actor(), 'i1', $payload);
                self::fail('Expected a refusal.');
            } catch (ApInvalid $invalid) {
                self::assertSame('Tell the AI what you need to ask the vendor', $invalid->getMessage());
            }
            try {
                $this->drafts->draftReply($this->world->actor(), 'e1', $payload);
                self::fail('Expected a refusal.');
            } catch (ApInvalid $invalid) {
                self::assertSame('Tell the AI what the reply should say', $invalid->getMessage());
            }
        }
        $this->expectException(ApNotFound::class);
        try {
            $this->drafts->draftReply($this->world->actor(), 'ghost', []);
        } finally {
            self::assertSame([], $this->client->calls);
        }
    }

    public function test_an_invoice_or_email_out_of_scope_or_missing_is_not_found(): void
    {
        $scoped = $this->world->actor(['melissa@example.test']);
        foreach ([
            fn () => $this->drafts->draftVendorEmail($scoped, 'i1', ['instruction' => 'x']),
            fn () => $this->drafts->draftVendorEmail($this->world->actor(), 'ghost', ['instruction' => 'x']),
            fn () => $this->drafts->draftReply($scoped, 'e1', ['instruction' => 'x']),
            fn () => $this->drafts->draftReply($this->world->actor(), 'ghost', ['instruction' => 'x']),
        ] as $draft) {
            try {
                $draft();
                self::fail('Expected not found.');
            } catch (ApNotFound $missing) {
                self::assertSame('Not found', $missing->getMessage());
            }
        }
        self::assertSame([], $this->client->calls);
    }

    public function test_a_provider_failure_is_a_502_with_its_message(): void
    {
        $this->client->answers['ap_vendor_email_draft'] = FinanceAiFailure::of(FinanceAiFailure::PROVIDER_UNAVAILABLE, 'The AI service is busy; try again.');
        $this->client->answers['ap_reply_draft'] = FinanceAiFailure::of(FinanceAiFailure::TIMEOUT, 'The AI service took too long.');

        try {
            $this->drafts->draftVendorEmail($this->world->actor(), 'i1', ['instruction' => 'x']);
            self::fail('Expected a failure.');
        } catch (ApUpstream $failed) {
            self::assertSame(['The AI service is busy; try again.', 502, 'ai'], [$failed->getMessage(), $failed->httpStatus(), $failed->extra['reason']]);
        }
        try {
            $this->drafts->draftReply($this->world->actor(), 'e1', ['instruction' => 'x']);
            self::fail('Expected a failure.');
        } catch (ApUpstream $failed) {
            self::assertSame('The AI service took too long.', $failed->getMessage());
        }
    }

    public function test_drafting_stores_and_sends_nothing(): void
    {
        $this->drafts->draftVendorEmail($this->world->actor(), 'i1', ['instruction' => 'x']);
        $this->drafts->draftReply($this->world->actor(), 'e1', ['instruction' => 'x']);

        self::assertSame([], $this->world->ledger->all());
        self::assertSame([], $this->world->register->audit->all());
        self::assertSame([], $this->world->transport->standalone);
    }
}
