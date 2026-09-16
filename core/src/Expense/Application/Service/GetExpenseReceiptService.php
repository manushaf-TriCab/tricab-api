<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Expense\Module;

final class GetExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly LegacyExpenseReadRepository $legacyReceipts,
        private readonly LegacyExpenseReceiptAssembler $legacyAssembler,
        private readonly LegacyExpenseIdentityResolver $legacyIdentity,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    public function execute(ExpenseActor $actor, string $publicId): ExpenseReceiptDetails
    {
        if (!$actor->hasPermission(Module::PERMISSION_ACCESS)) {
            throw ExpenseAccessDenied::permissionRequired();
        }

        $legacyRef = LegacyExpenseReceiptRef::tryParse($publicId);
        if ($legacyRef !== null) {
            return $this->getLegacy($actor, $legacyRef);
        }

        $profile = $this->profiles->findByUserId($actor->userId);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        $isOwner = $receipt->ownerUserId()->equals($actor->userId);
        $isManager = $receipt->managerUserId() !== null && $receipt->managerUserId()->equals($actor->userId);
        $canViewPrivileged = $profile !== null && $this->access->canMutateOthersReceipts($actor, $profile);

        if (!$isOwner && !$isManager && !$canViewPrivileged) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        return $this->assembler->toDetails($receipt);
    }

    private function getLegacy(ExpenseActor $actor, LegacyExpenseReceiptRef $legacyRef): ExpenseReceiptDetails
    {
        if (!$this->legacyReadEnabled) {
            throw ExpenseReceiptNotFound::forPublicId($legacyRef->toPublicId());
        }

        $profile = $this->profiles->findByUserId($actor->userId);
        $ownerKeys = $this->legacyIdentity->ownerKeys($profile);
        if ($ownerKeys === []) {
            // No resolved legacy identity → treat as not found (no cross-user disclosure).
            throw ExpenseReceiptNotFound::forPublicId($legacyRef->toPublicId());
        }

        $row = $this->legacyReceipts->findOwnedById($legacyRef->legacyReceiptId(), $ownerKeys);
        if ($row === null) {
            // Owned-by filter already applied — stranger gets the same 404 as missing.
            throw ExpenseReceiptNotFound::forPublicId($legacyRef->toPublicId());
        }

        // Suppress duplicate when migrated Azure row exists for this actor.
        foreach ($this->receipts->listOwnedBy($actor->userId) as $receipt) {
            if ($receipt->legacy()->legacyReceiptId() === $legacyRef->legacyReceiptId()) {
                return $this->assembler->toDetails($receipt);
            }
        }

        return $this->legacyAssembler->toDetails($row);
    }
}
