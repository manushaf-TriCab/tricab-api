<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseFinanceResolution;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class ResolveExpenseReceiptFinanceService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(
        ExpenseActor $actor,
        string $receiptPublicId,
        string $resolution,
        ?string $note,
        string $expectedRowVersion,
    ): array {
        $this->access->requireFinance($actor);

        $normalized = strtolower(trim($resolution));
        if (!in_array($normalized, Module::RECEIPT_FINANCE_RESOLUTIONS, true)) {
            throw InvalidExpenseValue::for('resolution', $resolution);
        }

        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($receiptPublicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($receiptPublicId);
        }

        // Receipt chased intentionally sends NO email (legacy).
        $receipt->applyFinanceResolution(
            ExpenseFinanceResolution::fromString($normalized),
            $note,
            $actor->userId,
            $this->clock->now(),
        );
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));

        $this->audit->record(
            $actor->userId,
            'receipt_finance_resolved',
            $saved->publicId(),
            $normalized,
        );

        return ExpenseFinanceViews::receipt($saved);
    }
}
