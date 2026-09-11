<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class PurgeExpenseReceiptService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAttachmentStore $attachments,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ExpenseActor $actor, string $publicId, string $expectedRowVersion): void
    {
        $profile = $this->access->requireActiveProfile($actor);
        if (!$this->access->hasFinanceCapability($actor, $profile)) {
            throw ExpenseAccessDenied::purgeForbidden();
        }

        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($publicId));
        if ($receipt === null || !$receipt->isDeleted()) {
            throw ExpenseReceiptNotFound::forPublicId($publicId);
        }

        if ($receipt->attachment() !== null) {
            $this->attachments->deleteIfManaged($receipt->attachment());
        }

        $this->receipts->deletePermanently($receipt, ExpenseRowVersion::require($expectedRowVersion));
        $this->audit->record($actor->userId, 'receipt_purged', $receipt->publicId());
    }

    /**
     * Scheduled cleanup hook — not invoked automatically in Phase 3.
     */
    public function purgeExpired(ExpenseActor $actor, DateTimeImmutable $now): int
    {
        unset($actor, $now, $this->clock);
        return 0;
    }

    public static function recycleDays(): int
    {
        return Module::RECYCLE_DAYS;
    }
}
