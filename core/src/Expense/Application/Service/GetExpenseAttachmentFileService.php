<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;

final class GetExpenseAttachmentFileService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAttachmentStore $attachments,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $publicId, int $expiresInSeconds = 300): array
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

        $ref = $receipt->attachment();
        if ($ref === null) {
            throw ExpenseAttachmentUnavailable::notFound();
        }

        $ticket = $this->attachments->issueAuthorizedRead($ref, max(60, $expiresInSeconds));
        if ($ticket === null) {
            throw ExpenseAttachmentUnavailable::legacyDualRead();
        }

        $ticket['fileName'] = $ref->fileName();
        $ticket['storageSource'] = $ref->storageSource();
        $ticket['dualReadClass'] = $ref->dualReadClass();

        return $ticket;
    }
}
