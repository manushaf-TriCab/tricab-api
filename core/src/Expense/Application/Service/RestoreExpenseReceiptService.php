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
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Shared\Time\Clock;

final class RestoreExpenseReceiptService
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
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
        $profile = $this->access->requireActiveProfile($actor);
        if (!$this->access->hasFinanceCapability($actor, $profile)) {
            throw ExpenseAccessDenied::restoreForbidden();
        }

        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }
        if (!$receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        $receipt->restore($this->clock->now());
        $saved = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record($actor->userId, 'receipt_restored', $saved->publicId());

        return $this->assembler->toDetails($saved);
    }
}
