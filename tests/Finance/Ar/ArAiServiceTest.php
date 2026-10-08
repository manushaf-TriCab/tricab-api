<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Task\RecordingFinanceAiClient;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Ar\Service\ArAiService;
use TriCab\Core\Finance\Ar\Service\ArDraftReplyService;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;

/** Tri-AR's model calls (T5.8) against a recording client: data only, the active options, failures typed and logged. */
final class ArAiServiceTest extends TestCase
{
    private RecordingFinanceAiClient $client;

    private InMemoryArStore $store;

    private ArOptionService $options;

    /** @var list<string> */
    private array $log = [];

    private ArAiService $ai;

    protected function setUp(): void
    {
        $this->client = new RecordingFinanceAiClient();
        $this->store = new InMemoryArStore();
        $time = new ArTime(FixedClock::utc('2026-09-22 03:00:00'), 'Australia/Melbourne');
        $this->options = new ArOptionService($this->store, $time);
        $this->ai = new ArAiService($this->client, $this->options, function (string $line): void {
            $this->log[] = $line;
        });
    }

    public function test_classify_sends_data_and_only_the_active_options_and_reads_the_decisions(): void
    {
        $this->options->update('category', 'credit_notes', ['active' => false]);
        $this->client->queue[] = [
            'follow_up_category' => 'remittance_advice', 'proposed_response_type' => 'email', 'needs_review' => false,
            'classification_confidence' => 0.93, 'follow_up_status' => 'in_progress',
        ];

        $out = $this->ai->classify('Pat Payer', 'pat@sample.local', 'Advice', 'Paid.');

        self::assertSame(['remittance_advice', 'email', false, 0.93, 'in_progress'], [$out->followUpCategory, $out->proposedResponseType, $out->needsReview, $out->confidence, $out->followUpStatus]);
        $call = $this->client->calls[0];
        self::assertSame('ar_classify', $call['purpose']);
        self::assertArrayNotHasKey('prompt', $call['data']);
        $categories = $call['data']['categories'];
        $methods = $call['data']['methods'];
        self::assertIsArray($categories);
        self::assertIsArray($methods);
        $values = array_column($categories, 'value');
        self::assertContains('remittance_advice', $values);
        self::assertNotContains('credit_notes', $values, 'an archived option is not offered to the classifier');
        self::assertEqualsCanonicalizing(['email', 'phone'], array_column($methods, 'value'));
    }

    public function test_prose_and_a_remittance_file_are_sent_as_data_with_the_file_in_base64(): void
    {
        $this->client->queue[] = ['customer_name' => 'Acme', 'ai_summary' => 'Paid.', 'proposed_response' => 'Thanks.'];
        $prose = $this->ai->prose('Pat', 'pat@sample.local', 'Advice', 'Paid.');
        self::assertSame(['Acme', 'Paid.', 'Thanks.'], [$prose->customerName, $prose->aiSummary, $prose->proposedResponse]);

        $this->client->queue[] = [
            'is_remittance' => true, 'payer_name' => 'Acme', 'payment_date' => '2026-10-01', 'payment_reference' => null, 'currency' => 'AUD',
            'total_amount' => 30.0, 'line_items' => [
                ['invoice_number' => 'INV-1', 'invoice_date' => null, 'gross_amount' => 10.0, 'discount_amount' => null, 'paid_amount' => 10.0],
                ['invoice_number' => 'INV-2', 'invoice_date' => null, 'gross_amount' => 20.0, 'discount_amount' => null, 'paid_amount' => 20.0],
            ], 'extraction_confidence' => 0.9, 'notes' => '',
        ];
        $extraction = $this->ai->extractRemittance('Advice', 'pat@sample.local', 'advice.pdf', 'application/pdf', '%PDF invented');

        self::assertTrue($extraction->isRemittance);
        self::assertCount(2, $extraction->lines);
        self::assertTrue($extraction->reconciles());
        self::assertNull($extraction->paymentReference);
        $call = $this->client->calls[1];
        self::assertSame('ar_remittance', $call['purpose']);
        self::assertSame(ArAiService::REMITTANCE_DEADLINE_MS, $call['deadlineMs']);
        self::assertSame(['name' => 'advice.pdf', 'content_type' => 'application/pdf', 'data' => base64_encode('%PDF invented')], $call['data']['file']);
    }

    public function test_reconciles_is_null_without_a_total_or_paid_amounts_and_false_when_they_differ(): void
    {
        $this->client->queue[] = ['is_remittance' => true, 'payer_name' => '', 'currency' => 'AUD', 'total_amount' => null, 'line_items' => [], 'extraction_confidence' => 0.5, 'notes' => ''];
        self::assertNull($this->ai->extractRemittance('', '', 'a.pdf', 'application/pdf', 'x')->reconciles());

        $this->client->queue[] = ['is_remittance' => true, 'payer_name' => '', 'currency' => 'AUD', 'total_amount' => 100.0, 'line_items' => [['invoice_number' => 'A', 'paid_amount' => 90.0]], 'extraction_confidence' => 0.5, 'notes' => ''];
        self::assertFalse($this->ai->extractRemittance('', '', 'a.pdf', 'application/pdf', 'x')->reconciles());
    }

    public function test_a_failure_is_a_typed_upstream_error_and_the_log_has_no_content(): void
    {
        $this->client->queue[] = FinanceAiFailure::of(FinanceAiFailure::PROVIDER_REJECTED, 'The model provider refused the request (HTTP 401).', 'corr-0123456789abcdef');

        try {
            $this->ai->prose('Pat', 'pat@sample.local', 'Advice', 'SECRET-BODY-MARKER');
            self::fail('Expected a failure.');
        } catch (ArUpstream $failure) {
            self::assertSame(502, $failure->httpStatus());
            self::assertSame(['reason' => 'ai'], $failure->extra);
            self::assertInstanceOf(FinanceAiFailure::class, $failure->getPrevious());
        }
        self::assertSame(['Tri-AR AI call failed (purpose ar_prose, code provider_rejected, correlation corr-0123456789abcdef)'], $this->log);
        self::assertStringNotContainsString('SECRET', implode(' ', $this->log));
    }

    public function test_the_draft_route_service_keeps_the_sources_messages(): void
    {
        $this->store->insert(ArFixtures::record('r1', senderEmail: 'pat@sample.local'));
        $drafts = new ArDraftReplyService($this->store, $this->ai);
        $this->client->queue[] = ['subject' => 'Re: x', 'body' => 'Hi'];

        self::assertSame(['subject' => 'Re: x', 'body' => 'Hi', 'to' => 'pat@sample.local'], $drafts->draft('r1', ['instruction' => ' Thank them ', 'previous_draft' => 'old']));
        self::assertSame('Thank them', $this->client->calls[0]['data']['instruction']);
        self::assertSame('old', $this->client->calls[0]['data']['previous_draft']);

        try {
            $drafts->draft('r1', ['instruction' => '  ']);
            self::fail('Expected a refusal.');
        } catch (ArInvalid $invalid) {
            self::assertSame('Tell the AI what the reply should say', $invalid->getMessage());
        }
        try {
            $drafts->draft('ghost', ['instruction' => 'x']);
            self::fail('Expected a 404.');
        } catch (ArNotFound) {
            self::assertCount(1, $this->client->calls);
        }
        $this->client->queue[] = FinanceAiFailure::of(FinanceAiFailure::TIMEOUT, 'The finance AI service did not answer in time.');
        try {
            $drafts->draft('r1', ['instruction' => 'x']);
            self::fail('Expected a 502.');
        } catch (ArUpstream $failure) {
            self::assertSame('AI draft failed: The finance AI service did not answer in time.', $failure->getMessage());
        }
    }
}
