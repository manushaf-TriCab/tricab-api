<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Ai;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\ManagedIdentityFoundryAccessTokenProvider;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ap2\Ai\Ap2Foundry;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2Copilot;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2DraftWriter;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2InvoiceExtractor;
use TriCab\Api\Finance\Ap2\Ai\FoundryAp2MailClassifier;
use TriCab\Api\Finance\Ap2\Ai\InMemoryAp2AiUsageLog;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiPricing;
use TriCab\Api\Finance\Ap2\InMemoryAp2MailStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ap2\Service\Ap2Time;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailContext;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;
use TriCab\Core\Finance\Ap2\Contract\Ap2CopilotTools;

/**
 * One real call per purpose against Foundry. SKIPPED unless `TRI_AP2_AI_SMOKE=1` and the Foundry connection is in the
 * environment (`FOUNDRY_ENDPOINT`, and `FOUNDRY_API_KEY` with `FOUNDRY_AUTH_MODE=api-key` on a laptop). The deployment
 * is `FINANCE_AP2_AI_SMOKE_MODEL` (default `claude-haiku-5-5`). Sample data only; it costs well under a cent.
 *
 *   TRI_AP2_AI_SMOKE=1 FOUNDRY_ENDPOINT=... FOUNDRY_AUTH_MODE=api-key FOUNDRY_API_KEY=... \
 *     vendor/bin/phpunit tests/Finance/Ap2/Ai/Ap2FoundryLiveSmokeTest.php
 */
final class Ap2FoundryLiveSmokeTest extends TestCase
{
    private InMemoryAp2AiUsageLog $usage;

    private Ap2Foundry $foundry;

    protected function setUp(): void
    {
        $endpoint = (string) getenv('FOUNDRY_ENDPOINT');
        $mode = strtolower((string) getenv('FOUNDRY_AUTH_MODE')) === Settings::FOUNDRY_AUTH_MODE_API_KEY ? Settings::FOUNDRY_AUTH_MODE_API_KEY : Settings::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY;
        $key = (string) getenv('FOUNDRY_API_KEY');
        if (getenv('TRI_AP2_AI_SMOKE') !== '1' || $endpoint === '' || ($mode === Settings::FOUNDRY_AUTH_MODE_API_KEY && $key === '')) {
            self::markTestSkipped('Live Foundry smoke test: set TRI_AP2_AI_SMOKE=1 and the Foundry connection to run it.');
        }
        $model = (string) getenv('FINANCE_AP2_AI_SMOKE_MODEL');
        $http = new StreamJsonHttpClient(60.0, 10.0);
        $this->usage = new InMemoryAp2AiUsageLog();
        $this->foundry = new Ap2Foundry($http, new ManagedIdentityFoundryAccessTokenProvider($http), $endpoint, $model !== '' ? $model : 'claude-haiku-5-5', $mode, $key, Ap2AiPricing::haiku55(), $this->usage);
    }

    protected function tearDown(): void
    {
        if (isset($this->usage)) {
            foreach ($this->usage->records as $u) {
                fwrite(STDERR, sprintf("\n[smoke] %s %s: %d in / %d out, USD %.6f, %d ms, %d call(s)\n", $u->purpose, $u->model, $u->inputTokens, $u->outputTokens, $u->costUsd, $u->durationMs, $u->calls));
            }
        }
    }

    public function testClassify(): void
    {
        $classifier = new FoundryAp2MailClassifier($this->foundry, Ap2AiWorld::prompt('tri-ap2-mail-classify'));
        $result = $classifier->classify(
            'Bank details changed — please update',
            'CleanWorks Accounts <office@cleanworks-billing.example>',
            'Hi, CleanWorks here. Please update our bank details before the next payment. New BSB 062-000, account 1234 5678.',
            [],
            [new Ap2SupplierRef(md5('S001'), 'Cable Materials Pty Ltd', 'accounts@cable.example', 'cable.example'), new Ap2SupplierRef(md5('S007'), 'CleanWorks', 'accounts@cleanworks.example', 'cleanworks.example')],
        );

        self::assertSame('Bank detail change', $result->category);
        self::assertSame(md5('S007'), $result->supplierId, 'the name in the body, though the domain differs');
        self::assertNotSame('', (string) $result->summary);
    }

    public function testExtractFromAPdf(): void
    {
        $extractor = new FoundryAp2InvoiceExtractor($this->foundry, Ap2AiWorld::prompt('tri-ap2-invoice-extract'));
        $pdf = self::pdf([
            'Cable Materials Pty Ltd    ABN 11 222 333 444',
            'TAX INVOICE',
            'Invoice No: CM-2200      Invoice date: 01/10/2026      Due date: 31/10/2026',
            'Bill to: Tricab Australia      Your order: PO-1001',
            '1000 x Cable 3C 2.5mm  @ AUD 20.00 = AUD 20,000.00',
            'Subtotal AUD 20,000.00    GST AUD 2,000.00    Total AUD 22,000.00',
        ]);

        $result = $extractor->extract($pdf, 'application/pdf', 'CM-2200.pdf', 'Cable Materials Pty Ltd');

        self::assertSame(['CM-2200', '2026-10-01', '2026-10-31', 'AUD', 'PO-1001'], [$result->number, $result->issued, $result->due, $result->currency, $result->po]);
        self::assertSame([20000.0, 2000.0, 22000.0], [$result->net, $result->tax, $result->total]);
        self::assertNull($result->credit);
    }

    public function testDraftAClarification(): void
    {
        $writer = new FoundryAp2DraftWriter($this->foundry, Ap2AiWorld::prompt('tri-ap2-reply-draft'));
        $mail = new Ap2MailContext('m1', 'Invoice CM-2200', 'accounts@cable.example', 'Please find invoice CM-2200 for 1,000 units. When will it be paid?', 'Supplier invoice', '2026-10-26T01:00:00Z');
        $invoice = ['number' => 'CM-2200', 'currency' => 'AUD', 'total' => 22000.0, 'po' => 'PO-1001', 'stage' => 'Awaiting matching'];

        $draft = $writer->draft($mail, new Ap2SupplierRef('s1', 'Cable Materials Pty Ltd', 'accounts@cable.example', 'cable.example'), $invoice, ['Received quantity 900 is below invoiced quantity 1000'], null);

        self::assertStringContainsString('CM-2200', $draft);
        self::assertFalse(FoundryAp2DraftWriter::promisesPayment($draft));
    }

    public function testCopilotLooksUpAndProposes(): void
    {
        $tools = new class() implements Ap2CopilotTools {
            public function invoices(string $supplierId): array
            {
                return ['found' => true, 'count' => 1, 'invoices' => [['id' => 'inv1', 'number' => 'CM-2200', 'currency' => 'AUD', 'total' => 22000.0, 'balance' => 22000.0, 'due' => '2026-10-31', 'stage' => 'Awaiting matching']]];
            }

            public function invoice(?string $invoiceId, ?string $supplierId, ?string $number): array
            {
                return ['found' => true, 'invoice' => ['id' => 'inv1', 'number' => 'CM-2200', 'validation' => ['Received quantity is below invoiced quantity']]];
            }

            public function exceptions(string $supplierId): array
            {
                return ['found' => true, 'invoices' => [['id' => 'inv1', 'number' => 'CM-2200', 'validation' => ['Received quantity is below invoiced quantity'], 'paymentIssues' => ['Invoice is not posted'], 'needsVerification' => true]]];
            }

            public function supplierSummary(string $supplierId): array
            {
                return ['found' => true, 'supplier' => ['id' => $supplierId, 'name' => 'Cable Materials Pty Ltd'], 'invoiceCount' => 1, 'postedOpenApAud' => 0, 'overduePostedApAud' => 0, 'paymentHold' => 'No supplier hold'];
            }

            public function history(string $supplierId, int $limit): array
            {
                return ['found' => true, 'entries' => []];
            }

            public function findSuppliers(string $query): array
            {
                return ['found' => true, 'suppliers' => [['id' => 'sup1', 'name' => 'Cable Materials Pty Ltd']]];
            }
        };
        $copilot = new FoundryAp2Copilot($this->foundry, $tools, new InMemoryAp2MailStore(), new Ap2Time(FixedClock::utc('2026-10-26 01:00:00'), 'Australia/Melbourne'), Ap2AiWorld::prompt('tri-ap2-copilot'));

        $reply = $copilot->respond(['supplierId' => 'sup1'], 'Explain the exceptions, then record a call: supplier will send the receipt', []);

        self::assertNotSame('', $reply->reply);
        self::assertContains('record_action', array_map(static fn ($p): string => $p->type, $reply->proposals));
    }

    /**
     * A one-page PDF with these lines in Helvetica (enough for the model to read; no library needed).
     *
     * @param list<string> $lines
     */
    private static function pdf(array $lines): string
    {
        $text = "BT /F1 11 Tf 50 780 Td 14 TL\n";
        foreach ($lines as $line) {
            $text .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ") Tj T*\n";
        }
        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}
