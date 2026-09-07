<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;

final class GetExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseReceiptAssembler $assembler,
    ) {
    }

    public function execute(ExpenseActor $actor, string $publicId): ExpenseReceiptDetails
    {
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        $isOwner = $receipt->ownerUserId()->equals($actor->userId);
        $isManager = $receipt->managerUserId() !== null && $receipt->managerUserId()->equals($actor->userId);
        $isFinance = $this->access->hasFinanceCapability($actor, $profile);
        $isAdmin = $profile->isExpenseAdmin();

        if (!$isOwner && !$isManager && !$isFinance && !$isAdmin) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        return $this->assembler->toDetails($receipt);
    }
}