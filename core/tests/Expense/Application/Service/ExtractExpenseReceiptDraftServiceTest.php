<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\AzureExpenseCaptureAssistPort;
use TriCab\Api\Expense\Persistence\DisabledExpenseCaptureAssistPort;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Api\Expense\Observability\ExpenseEventLogger;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseOcrSuggestion;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrFailed;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrUnavailable;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExtractExpenseReceiptDraftService;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Pre-create draft extraction: same assistive OCR path without a receipt id.
 */
final class ExtractExpenseReceiptDraftServiceTest extends TestCase
{
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseAuditRecorder $audit;
    private ExpenseAccessService $access;
    private UserId $ownerId;

    protected function setUp(): void
    {
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->audit = new InMemoryExpenseAuditRecorder();
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());
        $this->ownerId = UserId::fromInt(10);
        $this->profiles->save(new ExpenseProfile($this->ownerId, 'owner', 'owner@tricab.com', true, false, false, null, null));
    }

    public function test_extractsFieldsFromUploadBytesWithoutCreatingAReceipt(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 202, 'body' => '', 'headers' => ['operation-location' => 'https://di.example/ops/1']],
            ['status' => 200, 'body' => self::succeededAnalysis()],
        ];

        $result = $this->service($this->azurePort($http))->execute(
            $this->actor($this->ownerId),
            "\xFF\xD8\xFFjpeg-bytes",
            'invoice.jpg',
        );

        self::assertSame('image/jpeg', $result['mimeType']);
        self::assertFalse($result['applied']);
        self::assertArrayNotHasKey('receiptId', $result);

        $fields = $result['fields'];
        self::assertSame('WOOLWORTHS 1234 MELB', $fields['vendorName']['value']);
        self::assertSame(ExpenseOcrSuggestion::SOURCE_DOCUMENT_INTELLIGENCE, $fields['vendorName']['source']);
        self::assertSame('2026-08-14', $fields['receiptDate']['value']);
        self::assertSame('110.00', $fields['totalAmount']['value']);
        self::assertSame('10.00', $fields['gstAmount']['value']);
        self::assertSame('AUD', $fields['currency']['value']);
        self::assertSame(Module::GST_INCLUSIVE, $fields['gstType']['value']);
        self::assertSame(ExpenseOcrSuggestion::SOURCE_DERIVED, $fields['gstType']['source']);

        self::assertSame(['receipt_ocr_draft_suggested'], array_column($this->audit->events(), 'action'));
        self::assertNull($this->audit->events()[0]['receiptPublicId']);
    }

    public function test_rejectsWhenOcrDisabled(): void
    {
        $this->expectException(ExpenseOcrUnavailable::class);
        $this->service(new DisabledExpenseCaptureAssistPort())->execute(
            $this->actor($this->ownerId),
            "\xFF\xD8\xFFjpeg",
            'a.jpg',
        );
    }

    public function test_rejectsUnsupportedDocument(): void
    {
        $port = new class implements ExpenseCaptureAssistPort {
            public function isEnabled(): bool
            {
                return true;
            }

            public function suggestReceiptFields(string $fileContent, string $mimeType): array
            {
                throw new \RuntimeException('must not call Azure for unsupported types');
            }

            public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
            {
                return null;
            }
        };

        $this->expectException(ExpenseOcrUnavailable::class);
        $this->service($port)->execute($this->actor($this->ownerId), 'not-a-receipt', 'notes.txt');
    }

    public function test_rejectsOversizedDocument(): void
    {
        $port = new class implements ExpenseCaptureAssistPort {
            public function isEnabled(): bool
            {
                return true;
            }

            public function suggestReceiptFields(string $fileContent, string $mimeType): array
            {
                throw new \RuntimeException('must not call Azure for oversized files');
            }

            public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
            {
                return null;
            }
        };

        $bytes = str_repeat('x', Module::OCR_MAX_BYTES + 1);
        $this->expectException(ExpenseOcrUnavailable::class);
        $this->service($port)->execute($this->actor($this->ownerId), $bytes, 'huge.jpg');
    }

    public function test_failedExtractionSurfacesAsOcrFailed(): void
    {
        $port = new class implements ExpenseCaptureAssistPort {
            public function isEnabled(): bool
            {
                return true;
            }

            public function suggestReceiptFields(string $fileContent, string $mimeType): array
            {
                throw ExpenseOcrFailed::noFieldsExtracted();
            }

            public function suggestCategory(string $vendor, string $rawText, array $categories): ?string
            {
                return null;
            }
        };

        $this->expectException(ExpenseOcrFailed::class);
        $this->service($port)->execute($this->actor($this->ownerId), "\xFF\xD8\xFFjpeg", 'a.jpg');
    }

    private function azurePort(FakeJsonHttpClient $http): AzureExpenseCaptureAssistPort
    {
        return new AzureExpenseCaptureAssistPort(
            $http,
            new ExpenseEventLogger(retainForTests: true),
            'https://di.example',
            'di-key',
            pauseBetweenPolls: false,
        );
    }

    private function service(ExpenseCaptureAssistPort $capture): ExtractExpenseReceiptDraftService
    {
        return new ExtractExpenseReceiptDraftService($this->access, $capture, $this->audit);
    }

    private function actor(UserId $id): ExpenseActor
    {
        return new ExpenseActor($id, 'pub-' . $id->toInt(), 'u' . $id->toInt() . '@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
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
