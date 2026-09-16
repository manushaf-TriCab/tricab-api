<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
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
        LegacyExpenseReceiptRef::assertNotLegacy($publicId);
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
     * Scheduled recycle-bin cleanup. Purges receipts soft-deleted more than
     * {@see Module::RECYCLE_DAYS} ago.
     *
     * Runs without an actor because no human authorizes it; the caller is the
     * scheduler, and $dryRun defaults to true so an accidental invocation
     * reports rather than deletes. WordPress-owned attachments are reported and
     * skipped — legacy still serves those files, so deleting the row would
     * orphan them. One failing receipt does not abort the batch.
     *
     * @return array{
     *     dryRun: bool,
     *     cutoffUtc: string,
     *     eligible: int,
     *     deleted: int,
     *     blocked: int,
     *     receipts: list<array{publicId: string, deletedAtUtc: ?string, attachmentClass: string, action: string, reason: ?string}>
     * }
     */
    public function purgeExpired(bool $dryRun = true, ?DateTimeImmutable $now = null): array
    {
        $reference = $now ?? $this->clock->now();
        $cutoff = $reference->modify('-' . Module::RECYCLE_DAYS . ' days');

        $rows = [];
        $deleted = 0;
        $blocked = 0;

        foreach ($this->receipts->listAll(true) as $receipt) {
            $deletedAt = $receipt->deletedAt();
            if ($deletedAt === null || $deletedAt > $cutoff) {
                continue;
            }

            $attachmentClass = $receipt->attachment()?->dualReadClass() ?? 'none';

            if ($attachmentClass === 'legacy_wordpress') {
                $blocked++;
                $rows[] = self::row($receipt, $attachmentClass, 'skipped', 'wordpress_owned_attachment');
                continue;
            }

            if ($dryRun) {
                $rows[] = self::row($receipt, $attachmentClass, 'would_delete', null);
                continue;
            }

            try {
                if ($receipt->attachment() !== null) {
                    $this->attachments->deleteIfManaged($receipt->attachment());
                }
                $this->receipts->deletePermanently($receipt, $receipt->rowVersion());
                $deleted++;
                $rows[] = self::row($receipt, $attachmentClass, 'deleted', null);
            } catch (Throwable $failure) {
                $blocked++;
                $rows[] = self::row($receipt, $attachmentClass, 'failed', $failure->getMessage());
            }
        }

        return [
            'dryRun' => $dryRun,
            'cutoffUtc' => $cutoff->format(DATE_ATOM),
            'eligible' => count($rows),
            'deleted' => $deleted,
            'blocked' => $blocked,
            'receipts' => $rows,
        ];
    }

    public static function recycleDays(): int
    {
        return Module::RECYCLE_DAYS;
    }

    /**
     * @return array{publicId: string, deletedAtUtc: ?string, attachmentClass: string, action: string, reason: ?string}
     */
    private static function row(
        ExpenseReceipt $receipt,
        string $attachmentClass,
        string $action,
        ?string $reason,
    ): array {
        return [
            'publicId' => $receipt->publicId()->toString(),
            'deletedAtUtc' => $receipt->deletedAt()?->format(DATE_ATOM),
            'attachmentClass' => $attachmentClass,
            'action' => $action,
            'reason' => $reason,
        ];
    }
}
