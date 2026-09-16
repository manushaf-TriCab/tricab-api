<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Shared\Time\Clock;

final class ExpenseApprovalService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseNotificationPort $notifications,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    public function approve(ExpenseActor $actor, string $publicId, string $expectedRowVersion): ExpenseReceiptDetails
    {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->requireReceipt($publicId);
        $this->access->assertCanActAsManager($actor, $profile, $receipt);

        $receipt->approve($this->clock->now());
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record($actor->userId, 'receipt_approved', $saved->publicId());
        $this->notifications->notifyApproved($saved);

        return $this->assembler->toDetails($saved);
    }

    public function reject(ExpenseActor $actor, string $publicId, string $reason, string $expectedRowVersion): ExpenseReceiptDetails
    {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->requireReceipt($publicId);
        $this->access->assertCanActAsManager($actor, $profile, $receipt);

        $receipt->reject($reason, $this->clock->now());
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record($actor->userId, 'receipt_rejected', $saved->publicId(), $reason);
        $this->notifications->notifyRejected($saved, $reason);

        return $this->assembler->toDetails($saved);
    }

    public function sendBack(ExpenseActor $actor, string $publicId, string $expectedRowVersion, string $note = ''): ExpenseReceiptDetails
    {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);
        if (!$this->access->hasFinanceCapability($actor, $profile)) {
            throw ExpenseAccessDenied::sendBackForbidden();
        }

        $receipt = $this->requireReceipt($publicId);
        $receipt->sendBack($this->clock->now());
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record($actor->userId, 'receipt_sent_back', $saved->publicId(), $note !== '' ? $note : null);
        $this->notifications->notifySentBack($saved, $note);

        return $this->assembler->toDetails($saved);
    }

    private function requireReceipt(string $publicId): ExpenseReceipt
    {
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        return $receipt;
    }
}
