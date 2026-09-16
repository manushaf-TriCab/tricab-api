<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Expense\Observability\ExpenseEventLogger;
use TriCab\Api\Expense\Persistence\AzureExpenseCaptureAssistPort;
use TriCab\Api\Expense\Persistence\DisabledExpenseCaptureAssistPort;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseOcrSuggestion;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrFailed;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrUnavailable;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\RunExpenseReceiptOcrService;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Expense\Doubles\ExpensePhase5Fixtures;

/**
 * OCR is assistive: it reads the receipt's own managed attachment, suggests
 * values, and is forbidden from writing anything back.
 */
final class ExpensePhase5OcrTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseAttachmentStore $attachments;
    private InMemoryExpenseAuditRecorder $audit;
    private ExpenseAccessService $access;

    private UserId $ownerId;
    private UserId $strangerId;

    protected function setUp(): void
    {
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->attachments = new InMemoryExpenseAttachmentStore();
        $this->audit = new InMemoryExpenseAuditRecorder();
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());

        $this->ownerId = UserId::fromInt(10);
        $this->strangerId = UserId::fromInt(77);

        $this->profiles->save(new ExpenseProfile($this->ownerId, 'owner', 'owner@tricab.com', true, false, false, null, null));
        $this->profiles->save(new ExpenseProfile($this->strangerId, 'stranger', 'stranger@tricab.com', true, false, false, null, null));
    }

    public function test_documentIntelligenceResultBecomesASuggestion(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 202, 'body' => '', 'headers' => ['operation-location' => 'https://di.example/ops/1']],
            ['status' => 200, 'body' => self::succeededAnalysis()],
        ];

        $receipt = $this->seedReceiptWithAttachment('p5-ocr-a.jpg');
        $result = $this->service($this->azurePort($http))->execute($this->actor($this->ownerId), $receipt->publicId()->toString());

        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertFalse($result['applied'], 'a suggestion is never applied on the caller behalf');

        $fields = $result['fields'];
        self::assertSame('WOOLWORTHS 1234 MELB', $fields['vendorName']['value']);
        self::assertSame(0.97, $fields['vendorName']['confidence']);
        self::assertSame(ExpenseOcrSuggestion::SOURCE_DOCUMENT_INTELLIGENCE, $fields['vendorName']['source']);
        self::assertSame('2026-08-14', $fields['receiptDate']['value']);
        self::assertSame('110.00', $fields['totalAmount']['value']);
        self::assertSame('10.00', $fields['gstAmount']['value']);
        self::assertSame('AUD', $fields['currency']['value']);

        // 10.00 is within 0.05 of 110/11, so the legacy rule calls this GST-inclusive.
        self::assertSame(Module::GST_INCLUSIVE, $fields['gstType']['value']);
        self::assertSame(ExpenseOcrSuggestion::SOURCE_DERIVED, $fields['gstType']['source']);

        self::assertSame(['receipt_ocr_suggested'], array_column($this->audit->events(), 'action'));
    }

    public function test_extractionNeverModifiesTheReceipt(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 202, 'body' => '', 'headers' => ['operation-location' => 'https://di.example/ops/1']],
            ['status' => 200, 'body' => self::succeededAnalysis()],
        ];

        $receipt = $this->seedReceiptWithAttachment('p5-ocr-b.jpg');
        $before = $this->snapshot($receipt);

        $this->service($this->azurePort($http))->execute($this->actor($this->ownerId), $receipt->publicId()->toString());

        $after = $this->receipts->findByPublicId($receipt->publicId());
        self::assertNotNull($after);
        self::assertSame($before, $this->snapshot($after), 'the extractor only suggests; the receipt is untouched');
    }

    public function test_failedExtractionLeavesTheReceiptUnchanged(): void
    {
        $receipt = $this->seedReceiptWithAttachment('p5-ocr-c.jpg');
        $before = $this->snapshot($receipt);

        $port = new class implements ExpenseCaptureAssistPort {
            public function isEnabled(): bool
            {
                return true;
            }

            public function suggestReceiptFields(string $fileContent, string $mimeType): array
            {
                throw new RuntimeException('Document Intelligence is unreachable.');
            }

            public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
            {
                return null;
            }
        };

        try {
            $this->service($port)->execute($this->actor($this->ownerId), $receipt->publicId()->toString());
            self::fail('expected ExpenseOcrFailed');
        } catch (ExpenseOcrFailed) {
            // expected
        }

        $after = $this->receipts->findByPublicId($receipt->publicId());
        self::assertNotNull($after);
        self::assertSame($before, $this->snapshot($after));
        self::assertSame([], $this->audit->events(), 'a failure is not recorded as a suggestion');
    }

    public function test_documentIntelligenceFailureStatusSurfacesAsOcrFailed(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 202, 'body' => '', 'headers' => ['operation-location' => 'https://di.example/ops/1']],
            ['status' => 200, 'body' => '{"status":"failed"}'],
            ['status' => 202, 'body' => '', 'headers' => ['operation-location' => 'https://di.example/ops/2']],
            ['status' => 200, 'body' => '{"status":"failed"}'],
        ];

        $receipt = $this->seedReceiptWithAttachment('p5-ocr-d.jpg');

        $this->expectException(ExpenseOcrFailed::class);
        $this->service($this->azurePort($http))->execute($this->actor($this->ownerId), $receipt->publicId()->toString());
    }

    public function test_disabledOcrFailsWithAControlledError(): void
    {
        $receipt = $this->seedReceiptWithAttachment('p5-ocr-e.jpg');

        $this->expectException(ExpenseOcrUnavailable::class);
        $this->service(new DisabledExpenseCaptureAssistPort())
            ->execute($this->actor($this->ownerId), $receipt->publicId()->toString());
    }

    public function test_unconfiguredCredentialsLeaveThePortDisabled(): void
    {
        $port = new AzureExpenseCaptureAssistPort(
            new FakeJsonHttpClient(),
            new ExpenseEventLogger(retainForTests: true),
            '',
            '',
        );

        self::assertFalse($port->isEnabled());
    }

    public function test_userWithoutAccessToTheReceiptIsDenied(): void
    {
        $http = new FakeJsonHttpClient();
        $receipt = $this->seedReceiptWithAttachment('p5-ocr-f.jpg');

        $this->expectException(ExpenseAccessDenied::class);
        try {
            $this->service($this->azurePort($http))
                ->execute($this->actor($this->strangerId), $receipt->publicId()->toString());
        } finally {
            self::assertSame([], $http->calls, 'authorization is checked before any document is read');
        }
    }

    public function test_legacyWordpressAttachmentIsRejectedRatherThanFetchedByUrl(): void
    {
        $http = new FakeJsonHttpClient();
        $receipt = $this->receipts->save(ExpensePhase5Fixtures::draftReceipt(
            'wp',
            $this->ownerId,
            ExpenseAttachmentRef::fromLegacyUrl('https://legacy.tricab.com/wp-content/uploads/r.jpg', 'r.jpg'),
        ));
        self::assertSame('legacy_wordpress', $receipt->attachment()?->dualReadClass());

        $this->expectException(ExpenseAttachmentUnavailable::class);
        try {
            $this->service($this->azurePort($http))
                ->execute($this->actor($this->ownerId), $receipt->publicId()->toString());
        } finally {
            self::assertSame([], $http->calls, 'no OCR call is made against an unmanaged URL');
        }
    }

    public function test_receiptWithoutAnAttachmentHasNothingToRead(): void
    {
        $receipt = $this->receipts->save(ExpensePhase5Fixtures::draftReceipt('noatt', $this->ownerId));

        $this->expectException(ExpenseAttachmentUnavailable::class);
        $this->service($this->azurePort(new FakeJsonHttpClient()))
            ->execute($this->actor($this->ownerId), $receipt->publicId()->toString());
    }

    public function test_oversizedDocumentIsRejectedBeforeItIsSentToAzure(): void
    {
        $http = new FakeJsonHttpClient();
        $receipt = $this->seedReceiptWithAttachment(
            'p5-ocr-big.jpg',
            "\xFF\xD8\xFF" . str_repeat('x', Module::OCR_MAX_BYTES + 1),
        );

        $this->expectException(ExpenseOcrUnavailable::class);
        try {
            $this->service($this->azurePort($http))
                ->execute($this->actor($this->ownerId), $receipt->publicId()->toString());
        } finally {
            self::assertSame([], $http->calls);
        }
    }

    public function test_ocrLogsCarryNoDocumentBytes(): void
    {
        $logger = new ExpenseEventLogger(retainForTests: true);
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 202, 'body' => '', 'headers' => ['operation-location' => 'https://di.example/ops/1']],
            ['status' => 200, 'body' => self::succeededAnalysis()],
        ];

        $receipt = $this->seedReceiptWithAttachment('p5-ocr-g.jpg', "\xFF\xD8\xFFSECRET-RECEIPT-BYTES");
        $this->service($this->azurePort($http, $logger))->execute($this->actor($this->ownerId), $receipt->publicId()->toString());

        $recorded = json_encode($logger->recorded(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SECRET-RECEIPT-BYTES', $recorded);
        self::assertStringNotContainsString('di-key', $recorded);
        self::assertSame(
            ['expense.ocr.started', 'expense.ocr.completed'],
            array_column($logger->recorded(), 'event'),
        );
        self::assertSame(23, $logger->recorded()[0]['documentSize']);
    }

    private function azurePort(FakeJsonHttpClient $http, ?ExpenseEventLogger $logger = null): AzureExpenseCaptureAssistPort
    {
        return new AzureExpenseCaptureAssistPort(
            $http,
            $logger ?? new ExpenseEventLogger(retainForTests: true),
            'https://di.example',
            'di-key',
            pauseBetweenPolls: false,
        );
    }

    private function service(ExpenseCaptureAssistPort $capture): RunExpenseReceiptOcrService
    {
        return new RunExpenseReceiptOcrService(
            $this->access,
            $this->receipts,
            $this->attachments,
            $capture,
            $this->audit,
        );
    }

    private function seedReceiptWithAttachment(string $blobPath, ?string $bytes = null): ExpenseReceipt
    {
        $this->attachments->put($blobPath, $bytes ?? "\xFF\xD8\xFFjpeg-bytes");

        return $this->receipts->save(ExpensePhase5Fixtures::draftReceipt(
            $blobPath,
            $this->ownerId,
            ExpenseAttachmentRef::azureBlob($blobPath, basename($blobPath)),
        ));
    }

    private function actor(UserId $id): ExpenseActor
    {
        return new ExpenseActor($id, 'pub-' . $id->toInt(), 'u' . $id->toInt() . '@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
    }

    /** @return array<string, string|null> */
    private function snapshot(ExpenseReceipt $receipt): array
    {
        return [
            'vendor' => $receipt->vendorName(),
            'total' => $receipt->totalAmount()->amount(),
            'gst' => $receipt->gstAmount()->amount(),
            'gstType' => $receipt->gstType(),
            'currency' => $receipt->currency()->toString(),
            'category' => $receipt->category()->rawValue(),
            'status' => $receipt->status()->toString(),
            'rowVersion' => $receipt->rowVersion()?->toBase64(),
        ];
    }

    private static function succeededAnalysis(): string
    {
        return json_encode([
            'status' => 'succeeded',
            'analyzeResult' => [
                'content' => 'WOOLWORTHS 1234 MELB  TOTAL 110.00  GST 10.00',
                'documents' => [[
                    'fields' => [
                        'MerchantName' => ['valueString' => 'WOOLWORTHS 1234 MELB', 'confidence' => 0.97],
                        'TransactionDate' => ['valueDate' => '2026-08-14', 'confidence' => 0.93],
                        'Total' => [
                            'valueCurrency' => ['amount' => 110.0, 'currencyCode' => 'AUD'],
                            'confidence' => 0.95,
                        ],
                        'TotalTax' => ['valueNumber' => 10.0, 'confidence' => 0.88],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
