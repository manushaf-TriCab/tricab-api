<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Module;

final class GetExpenseReconciliationService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpensePeriodRepository $periods,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ExpenseActor $actor,
        ?string $country = null,
        ?string $periodPublicId = null,
    ): array {
        $this->access->requireFinance($actor);

        $countryCode = $country !== null && trim($country) !== ''
            ? strtoupper(trim($country))
            : null;

        $period = null;
        if ($periodPublicId !== null && trim($periodPublicId) !== '') {
            $period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString($periodPublicId));
            if ($period === null) {
                throw ExpensePeriodNotFound::forPublicId($periodPublicId);
            }
            $countryCode = $period->country();
        }

        $bankEntries = [];
        if ($period !== null && $period->id() !== null) {
            $bankEntries = $this->bankFeeds->listByPeriodId($period->id());
        } elseif ($countryCode !== null) {
            $bankEntries = $this->bankFeeds->listByCountry($countryCode);
        } else {
            $bankEntries = $this->bankFeeds->listAll();
        }

        $unmatchedBank = [];
        $matched = 0;
        $ambiguous = 0;
        $resolved = 0;
        foreach ($bankEntries as $entry) {
            if ($entry->matchStatus()->isMatched()) {
                $matched++;
            } elseif ($entry->matchStatus()->isAmbiguous()) {
                $ambiguous++;
            }
            if ($entry->resolution() !== null) {
                $resolved++;
            }
            if (
                !$entry->matchStatus()->isMatched()
                && ($entry->resolution() === null || $entry->resolution()->isChased())
            ) {
                $unmatchedBank[] = ExpenseFinanceViews::bankEntry($entry);
            }
        }

        if ($period !== null) {
            $txStart = $period->transactionStartDate() ?? $period->periodStart();
            $txEnd = $period->transactionEndDate() ?? $period->periodEnd();
            $receiptPool = $this->receipts->listInReceiptDateRange($txStart, $txEnd, false);
        } else {
            $receiptPool = $this->receipts->listAll(false);
        }

        $unmatchedReceipts = [];
        foreach ($receiptPool as $receipt) {
            if ($receipt->bankFeedEntryId() !== null) {
                continue;
            }
            if ($receipt->status()->isDraft()) {
                continue;
            }
            $resolution = $receipt->resolution()?->toString();
            if ($resolution !== null && $resolution !== Module::RESOLUTION_CHASED) {
                continue;
            }
            $unmatchedReceipts[] = ExpenseFinanceViews::receipt($receipt);
        }

        return [
            'country' => $countryCode,
            'period' => $period !== null ? ExpenseFinanceViews::period($period) : null,
            'counts' => [
                'bankTotal' => count($bankEntries),
                'matched' => $matched,
                'ambiguous' => $ambiguous,
                'resolved' => $resolved,
                'unmatchedBank' => count($unmatchedBank),
                'unmatchedReceipts' => count($unmatchedReceipts),
            ],
            'unmatchedBank' => $unmatchedBank,
            'unmatchedReceipts' => $unmatchedReceipts,
        ];
    }
}
