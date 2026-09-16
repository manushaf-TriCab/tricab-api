<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Domain\Service\ExpenseGstCalculator;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class UpdateExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(ExpenseActor $actor, string $publicId, array $payload): ExpenseReceiptDetails
    {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        foreach (['ownerUserId', 'createdBy', 'submittedBy', 'ownerEmail', 'userId'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw ExpenseAccessDenied::ownershipForbidden();
            }
        }

        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }
        if (!$receipt->ownerUserId()->equals($actor->userId) && !$this->access->canMutateOthersReceipts($actor, $profile)) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        $receiptDate = $receipt->receiptDate();
        if (isset($payload['receiptDate']) && is_string($payload['receiptDate']) && $payload['receiptDate'] !== '') {
            $receiptDate = new DateTimeImmutable($payload['receiptDate']);
        }

        $total = ExpenseMoney::fromString((string) ($payload['totalAmount'] ?? $receipt->totalAmount()->amount()));
        $gstType = ExpenseGstCalculator::normalizeType((string) ($payload['gstType'] ?? $receipt->gstType()));
        if ($gstType === Module::GST_INCLUSIVE && !array_key_exists('gstAmount', $payload)) {
            $gst = ExpenseGstCalculator::inclusiveFromTotal($total);
        } else {
            $gst = ExpenseMoney::fromString((string) ($payload['gstAmount'] ?? $receipt->gstAmount()->amount()));
        }
        ExpenseGstCalculator::assertPossible($total, $gst);

        $receipt->updateDraftDetails(
            vendorName: (string) ($payload['vendorName'] ?? $receipt->vendorName()),
            totalAmount: $total,
            gstAmount: $gst,
            currency: ExpenseCurrency::fromString((string) ($payload['currency'] ?? $receipt->currency()->toString())),
            gstType: $gstType,
            receiptDate: $receiptDate,
            category: ExpenseCategory::fromLegacy(
                isset($payload['category']) ? (string) $payload['category'] : $receipt->category()->rawValue(),
                isset($payload['otherCategory']) ? (string) $payload['otherCategory'] : $receipt->category()->otherCategory(),
            ),
            costCentre: (string) ($payload['costCentre'] ?? $receipt->costCentre()),
            description: array_key_exists('description', $payload)
                ? (isset($payload['description']) ? (string) $payload['description'] : null)
                : $receipt->description(),
            now: $this->clock->now(),
        );

        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($payload['expectedRowVersion'] ?? null));
        $this->audit->record($actor->userId, 'receipt_updated', $saved->publicId());

        return $this->assembler->toDetails($saved);
    }
}