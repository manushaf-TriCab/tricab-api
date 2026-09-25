<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpensePeriod;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;
use TriCab\Core\Expense\Module;

final class PreCloseExpensePeriodService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpensePeriodRepository $periods,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
    ) {
    }

    /**
     * @return array{canClose: bool, blockers: list<array{type: string, id: ?int, publicId: ?string, detail: string}>}
     */
    public function execute(ExpenseActor $actor, string $periodPublicId): array
    {
        $this->access->requireFinance($actor);
        LegacyExpensePeriodRef::assertNotLegacy($periodPublicId);

        $period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString($periodPublicId));
        if ($period === null || $period->id() === null) {
            throw ExpensePeriodNotFound::forPublicId($periodPublicId);
        }

        return $this->evaluate($period);
    }

    /**
     * @return array{canClose: bool, blockers: list<array{type: string, id: ?int, publicId: ?string, detail: string}>}
     */
    public function evaluate(ExpensePeriod $period): array
    {
        $blockers = [];
        $periodId = (int) $period->id();

        foreach ($this->bankFeeds->listByPeriodId($periodId) as $entry) {
            if ($entry->matchStatus()->isMatched()) {
                continue;
            }
            $resolution = $entry->resolution()?->toString();
            if ($resolution !== null && $resolution !== Module::RESOLUTION_CHASED) {
                continue;
            }
            $blockers[] = [
                'type' => 'unmatched_bank',
                'id' => $entry->id(),
                'publicId' => $entry->publicId()->toString(),
                'detail' => sprintf(
                    'Bank feed %s is %s with resolution %s',
                    $entry->publicId()->toString(),
                    $entry->matchStatus()->toString(),
                    $resolution ?? 'null',
                ),
            ];
        }

        $txStart = $period->transactionStartDate() ?? $period->periodStart();
        $txEnd = $period->transactionEndDate() ?? $period->periodEnd();
        $inRange = $this->receipts->listInReceiptDateRange($txStart, $txEnd, false);

        foreach ($inRange as $receipt) {
            $resolution = $receipt->resolution()?->toString();
            $blockingResolution = $resolution === null || $resolution === Module::RESOLUTION_CHASED;

            if (
                $receipt->bankFeedEntryId() === null
                && ($receipt->status()->isPending() || $receipt->status()->isApproved())
                && $blockingResolution
            ) {
                $blockers[] = [
                    'type' => 'unmatched_receipt',
                    'id' => $receipt->id()?->toInt(),
                    'publicId' => $receipt->publicId()->toString(),
                    'detail' => sprintf('Receipt %s has no bank link', $receipt->publicId()->toString()),
                ];
            }

            if ($receipt->status()->isPending() && $blockingResolution) {
                $blockers[] = [
                    'type' => 'pending_approval',
                    'id' => $receipt->id()?->toInt(),
                    'publicId' => $receipt->publicId()->toString(),
                    'detail' => sprintf('Receipt %s is still pending approval', $receipt->publicId()->toString()),
                ];
            }
        }

        return [
            'canClose' => $blockers === [],
            'blockers' => $blockers,
        ];
    }
}
