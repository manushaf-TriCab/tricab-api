<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Module;

/**
 * Near-miss diagnostic helper only — does NOT change match classification.
 * Tolerance = max(NEAR_MISS_FLOOR, amount * NEAR_MISS_FRACTION).
 */
final class ExpenseNearMissDiagnostics
{
    /**
     * @param list<ExpenseReceipt> $receipts
     * @return list<array{receiptId: int, amountDelta: string, reason: string}>
     */
    public function describe(ExpenseBankFeedEntry $entry, array $receipts): array
    {
        [$bankAmount] = $entry->matchAmountAndCurrency();
        $tolerance = $this->toleranceFor($bankAmount);
        $out = [];

        foreach ($receipts as $receipt) {
            if ($receipt->id() === null || $receipt->isDeleted() || $receipt->status()->isDraft()) {
                continue;
            }
            $delta = $bankAmount->add($receipt->totalAmount()->negate());
            $abs = $delta->abs()->amount();
            if (bccomp($abs, Module::MATCH_AMOUNT_TOLERANCE, 6) <= 0) {
                continue;
            }
            if (bccomp($abs, $tolerance, 6) <= 0) {
                $out[] = [
                    'receiptId' => $receipt->id()->toInt(),
                    'amountDelta' => $abs,
                    'reason' => 'amount_near_miss',
                ];
            }
        }

        return $out;
    }

    public function toleranceFor(ExpenseMoney $amount): string
    {
        $fraction = bcmul($amount->abs()->amount(), Module::NEAR_MISS_FRACTION, 6);
        if (bccomp($fraction, Module::NEAR_MISS_FLOOR, 6) < 0) {
            return Module::NEAR_MISS_FLOOR;
        }

        return $fraction;
    }
}
