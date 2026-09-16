<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Shared\Time\Clock;

final class CompleteExpenseAttachmentService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAttachmentStore $attachments,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    public function execute(
        ExpenseActor $actor,
        string $publicId,
        string $blobPath,
        string $fileName,
        string $expectedRowVersion,
    ): ExpenseReceiptDetails {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }
        $privileged = $this->access->canMutateOthersReceipts($actor, $profile);
        if (!$receipt->ownerUserId()->equals($actor->userId) && !$privileged) {
            throw ExpenseAccessDenied::viewForbidden();
        }
        if (!$this->attachments->objectExists($blobPath)) {
            throw ExpenseAttachmentUnavailable::missingObject();
        }

        $replaced = $receipt->attachment() !== null;
        $receipt->replaceAttachment(ExpenseAttachmentRef::azureBlob($blobPath, $fileName), $this->clock->now());
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record(
            $actor->userId,
            $replaced ? 'attachment_replaced' : 'attachment_uploaded',
            $saved->publicId(),
            $fileName,
        );

        return $this->assembler->toDetails($saved);
    }
}
