<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseManagerRequired;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseManagerResolver;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Shared\Time\Clock;

final class SubmitExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseManagerResolver $managers,
        private readonly ExpenseNotificationPort $notifications,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseReceiptAssembler $assembler,
        private readonly Clock $clock,
    ) {
    }

    public function execute(
        ExpenseActor $actor,
        string $publicId,
        string $expectedRowVersion,
        string $submitMode = Module::SUBMIT_DRAFT_THEN_SUBMIT,
    ): ExpenseReceiptDetails {
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);
        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || $receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }
        if (!$receipt->ownerUserId()->equals($actor->userId)) {
            throw ExpenseAccessDenied::viewForbidden();
        }

        $token = ExpenseRowVersion::require($expectedRowVersion);
        $frozen = $receipt->managerUserId();
        if ($frozen === null) {
            $legacyLogin = $receipt->legacy()->managerLogin();
            if ($legacyLogin !== null) {
                $mapped = $this->profiles->findByLegacyLogin($legacyLogin);
                if ($mapped !== null) {
                    $frozen = $mapped->userId();
                }
            }
        }
        if ($frozen === null) {
            $frozen = $this->managers->resolveManagerUserId($profile);
        }
        if ($frozen === null) {
            throw ExpenseManagerRequired::forSubmit();
        }

        $newlyFrozen = $receipt->freezeManager($frozen);
        $receipt->submit($actor->userId, $frozen, $this->clock->now());
        $saved = $this->receipts->save($receipt, $token);
        if ($newlyFrozen) {
            $this->audit->record($actor->userId, 'manager_assignment_frozen', $saved->publicId(), $frozen->toInt() . '');
        }
        $this->audit->record($actor->userId, 'receipt_submitted', $saved->publicId(), $submitMode);
        $this->notifications->notifySubmitted($saved, $submitMode);

        return $this->assembler->toDetails($saved);
    }
}
