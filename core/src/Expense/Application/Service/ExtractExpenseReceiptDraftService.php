<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use Throwable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseOcrSuggestion;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrFailed;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrUnavailable;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;
use TriCab\Core\Expense\Domain\Service\ExpenseOcrGstClassifier;
use TriCab\Core\Expense\Domain\Service\ExpenseOcrInputValidator;

/**
 * Pre-create assistive extraction: reads temporary upload bytes and returns
 * suggestions without creating a receipt or writing an attachment.
 *
 * Same capture port, GST classifier, and category suggestion path as
 * {@see RunExpenseReceiptOcrService}, but no receipt/attachment lookup.
 */
final class ExtractExpenseReceiptDraftService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseCaptureAssistPort $capture,
        private readonly ExpenseAuditRecorder $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $fileContent, string $fileName): array
    {
        $this->access->requireActiveProfile($actor);

        if (!$this->capture->isEnabled()) {
            throw ExpenseOcrUnavailable::notConfigured();
        }

        $inspection = ExpenseOcrInputValidator::inspect($fileContent, $fileName);
        if ($inspection['ok'] !== true || $inspection['mimeType'] === null) {
            throw ExpenseOcrUnavailable::inputRejected((string) ($inspection['reason'] ?? 'unsupported'));
        }

        try {
            $payload = $this->capture->suggestReceiptFields($fileContent, $inspection['mimeType']);
        } catch (ExpenseOcrFailed | ExpenseOcrUnavailable $known) {
            throw $known;
        } catch (Throwable $failure) {
            throw ExpenseOcrFailed::extraction($failure);
        }

        $suggestion = ExpenseOcrSuggestion::fromPortPayload($payload);
        if ($suggestion->toArray()['fields'] === []) {
            throw ExpenseOcrFailed::noFieldsExtracted();
        }

        $suggestion = $this->applyGstClassification($suggestion);
        $suggestion = $this->applyCategorySuggestion($suggestion, $payload);

        $this->audit->record(
            $actor->userId,
            'receipt_ocr_draft_suggested',
            null,
            $inspection['mimeType'],
        );

        return [
            'mimeType' => $inspection['mimeType'],
            'pages' => $inspection['pages'],
            ...$suggestion->toArray(),
        ];
    }

    private function applyGstClassification(ExpenseOcrSuggestion $suggestion): ExpenseOcrSuggestion
    {
        $classified = ExpenseOcrGstClassifier::classify(
            $suggestion->value('totalAmount'),
            $suggestion->value('gstAmount'),
        );

        $existing = $suggestion->field('gstType');
        $suggestion = $suggestion->withField(
            'gstType',
            $classified['gstType'],
            $existing['confidence'] ?? null,
            ExpenseOcrSuggestion::SOURCE_DERIVED,
        );

        if ($classified['suspicious']) {
            $suggestion = $suggestion->withWarning('gst_' . $classified['reason']);
        }

        return $suggestion;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCategorySuggestion(ExpenseOcrSuggestion $suggestion, array $payload): ExpenseOcrSuggestion
    {
        if ($suggestion->value('categoryCode') !== null) {
            return $suggestion;
        }

        $vendor = $suggestion->value('vendorName');
        if ($vendor === null) {
            return $suggestion;
        }

        $rawText = is_string($payload['rawText'] ?? null) ? $payload['rawText'] : '';

        try {
            $code = $this->capture->suggestCategory($vendor, $rawText, ExpenseCatalog::categories());
        } catch (Throwable) {
            return $suggestion->withWarning('category_suggestion_unavailable');
        }

        if ($code === null || !array_key_exists($code, ExpenseCatalog::categories())) {
            return $suggestion;
        }

        return $suggestion->withField(
            'categoryCode',
            $code,
            null,
            ExpenseOcrSuggestion::SOURCE_OPENAI,
        );
    }
}
