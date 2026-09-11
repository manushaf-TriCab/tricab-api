<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Shared\Time\Clock;

final class SoftDeleteExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ExpenseActor $actor, string $publicId, string $expectedRowVersion): ExpenseReceiptDetails
    {
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        $isOwner = $receipt->ownerUserId()->equals($actor->userId);
        $isFinance = $this->access->hasFinanceCapability($actor, $profile);
        $ownerMay = $isOwner && ($receipt->status()->isDraft() || $receipt->status()->isPending());
        if (!$isFinance && !$ownerMay) {
            throw ExpenseAccessDenied::deleteForbidden();
        }

        $receipt->softDelete($actor->emailAddress, $this->clock->now());
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record($actor->userId, 'receipt_deleted', $saved->publicId());

        return $this->assembler->toDetails($saved);
    }
}
