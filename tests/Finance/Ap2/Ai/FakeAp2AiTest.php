<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Ai;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\Ai\ErrorLogAp2AiUsageLog;
use TriCab\Api\Finance\Ap2\Ai\FakeAp2InvoiceExtractor;
use TriCab\Api\Finance\Ap2\Ai\FakeAp2MailClassifier;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;

/** The model-free stand-ins keep the real contracts (nothing made up), and the usage line carries no text. */
final class FakeAp2AiTest extends TestCase
{
    public function testTheFakeClassifierSortsThePrototypesSeedMail(): void
    {
        $fake = new FakeAp2MailClassifier();
        $suppliers = [new Ap2SupplierRef('s1', 'Cable Materials', 'accounts@cable.example', 'cable.example')];

        $cases = [
            ['Invoice CM-2200', 'Please find invoice CM-2200 for 1,000 units.', 'Supplier invoice'],
            ['Bank details changed — please update', 'Please update our bank details before the next payment.', 'Bank detail change'],
            ['Credit note CN-2200 for short receipt', 'Please find credit note CN-2200.', 'Credit note'],
            ['Supplier statement — River Equipment', 'Our statement shows AUD 6,600 outstanding.', 'Supplier statement'],
            ['Service invoice and payment query', 'When will invoice 4410 be paid?', 'Payment enquiry'],
            ['Hello', 'Our office is closed.', 'Other'],
        ];
        foreach ($cases as [$subject, $body, $category]) {
            $result = $fake->classify($subject, 'accounts@cable.example', $body, [], $suppliers);
            self::assertSame($category, $result->category, $subject);
            self::assertNull($result->supplierId, 'the sync matches the sender itself');
        }

        $fake->failWith = new Ap2AiUnavailable('The AI classification failed: test');
        $this->expectException(Ap2AiUnavailable::class);
        $fake->classify('x', 'y', 'z', [], $suppliers);
    }

    public function testTheFakeExtractorReadsNothingUnlessTold(): void
    {
        $fake = new FakeAp2InvoiceExtractor();
        self::assertEquals(Ap2ExtractedInvoice::none(), $fake->extract('%PDF', 'application/pdf', 'a.pdf', null));

        $fake->next = new Ap2ExtractedInvoice(number: 'CM-2200', total: 22000.0, confidence: ['number' => 0.8, 'total' => 0.8], overall: 0.8);
        self::assertSame('CM-2200', $fake->extract('%PDF', 'application/pdf', 'a.pdf', 'Cable Materials')->number);
        self::assertSame('Cable Materials', $fake->calls[1]['supplierHint']);
    }

    public function testTheUsageLineCarriesNoText(): void
    {
        $usage = new Ap2AiUsage(Ap2AiUsage::PURPOSE_EXTRACT, 'claude-haiku-5-5', 'tri-ap2-invoice-extract-v1', 1200, 300, 0, 0, 0.00027, 900, 1, true);
        self::assertSame([
            'purpose' => 'ap2_invoice_extract', 'model' => 'claude-haiku-5-5', 'prompt' => 'tri-ap2-invoice-extract-v1',
            'inputTokens' => 1200, 'outputTokens' => 300, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0,
            'costUsd' => 0.00027, 'durationMs' => 900, 'calls' => 1, 'ok' => true,
        ], $usage->toArray());

        $log = ini_get('error_log');
        $file = tempnam(sys_get_temp_dir(), 'ap2usage');
        self::assertIsString($file);
        ini_set('error_log', $file);
        try {
            (new ErrorLogAp2AiUsageLog())->record($usage);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
        $written = (string) file_get_contents($file);
        @unlink($file);
        self::assertStringContainsString('Tri-AP2 AI usage {"purpose":"ap2_invoice_extract"', $written);
    }
}
