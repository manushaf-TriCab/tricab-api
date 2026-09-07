<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseManagerRequired;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseManagerResolver;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Shared\Time\Clock;

final class SubmitExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseManagerResolver $managers,
        private readonly ExpenseNotificationPort $notifications,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ExpenseActor $actor, string $publicId): ExpenseReceiptDetails
    {
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }
        if (!$receipt->ownerUserId()->equals($actor->userId)) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        $managerUserId = $this->managers->resolveManagerUserId($profile) ?? $receipt->managerUserId();
        if ($managerUserId === null) {
            throw ExpenseManagerRequired::forSubmit();
        }

        $receipt->submit($actor->userId, $managerUserId, $this->clock->now());
        $saved = $this->receipts->save($receipt);
        $this->audit->record($actor->userId, 'receipt_submitted', $saved->publicId());
        $this->notifications->notifySubmitted($saved);

        return $this->assembler->toDetails($saved);
    }
}