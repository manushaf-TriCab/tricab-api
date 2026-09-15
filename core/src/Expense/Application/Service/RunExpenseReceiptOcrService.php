<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use Throwable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseOcrSuggestion;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrFailed;
use TriCab\Core\Expense\Application\Exception\ExpenseOcrUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Service\ExpenseOcrGstClassifier;
use TriCab\Core\Expense\Domain\Service\ExpenseOcrInputValidator;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;

/**
 * Runs assistive extraction over a receipt's own attachment and returns
 * suggestions. It never writes to the receipt: a failed or partial extraction
 * leaves the aggregate exactly as it was, which is why there is no row version
 * argument and no save call anywhere in here.
 */
final class RunExpenseReceiptOcrService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAttachmentStore $attachments,
        private readonly ExpenseCaptureAssistPort $capture,
        private readonly ExpenseAuditRecorder $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $publicId): array
    {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);

        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        $isOwner = $receipt->ownerUserId()->equals($actor->userId);
        $isManager = $receipt->managerUserId() !== null && $receipt->managerUserId()->equals($actor->userId);
        if (!$isOwner && !$isManager && !$this->access->canMutateOthersReceipts($actor, $profile)) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        if (!$this->capture->isEnabled()) {
            throw ExpenseOcrUnavailable::notConfigured();
        }

        $ref = $receipt->attachment();
        if ($ref === null) {
            throw ExpenseAttachmentUnavailable::notFound();
        }

        // Legacy WordPress attachments have no managed copy to read, and OCR is
        // never allowed to fetch a bare URL. Surface the migration requirement.
        $bytes = $this->attachments->readManagedBytes($ref);
        if ($bytes === null) {
            throw ExpenseAttachmentUnavailable::legacyDualRead();
        }

        $inspection = ExpenseOcrInputValidator::inspect($bytes, $ref->fileName());
        if ($inspection['ok'] !== true || $inspection['mimeType'] === null) {
            throw ExpenseOcrUnavailable::inputRejected((string) ($inspection['reason'] ?? 'unsupported'));
        }

        try {
            $payload = $this->capture->suggestReceiptFields($bytes, $inspection['mimeType']);
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
            'receipt_ocr_suggested',
            $receipt->publicId(),
            $inspection['mimeType'],
        );

        return [
            'receiptId' => $receipt->publicId()->toString(),
            'mimeType' => $inspection['mimeType'],
            'pages' => $inspection['pages'],
            ...$suggestion->toArray(),
        ];
    }

    /**
     * The extractor reports a GST amount but not whether the printed total
     * already includes it; that call belongs to the legacy domain rule.
     */
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
            // A category hint is the least valuable part of the result; losing it
            // must not cost the user the extraction they already paid for.
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
