<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

use DateInterval;
use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Module;

/**
 * Legacy auto-match rules (exact).
 *
 * Payment transaction type is NOT excluded from auto-match (legacy quirk).
 */
final class ExpenseBankFeedMatcher
{
    /**
     * @param list<ExpenseReceipt> $candidateReceipts Unmatched, non-draft, non-deleted receipts
     * @param array<int, ExpenseProfile> $profilesByUserId
     * @return list<ExpenseReceipt>
     */
    public function findCandidates(
        ExpenseBankFeedEntry $entry,
        array $candidateReceipts,
        array $profilesByUserId,
    ): array {
        if (!$entry->matchStatus()->isUnmatched()) {
            return [];
        }

        [$bankAmount, $bankCurrency] = $entry->matchAmountAndCurrency();
        $txDate = $entry->transactionDate();
        $matches = [];

        foreach ($candidateReceipts as $receipt) {
            if ($receipt->isDeleted()) {
                continue;
            }
            if ($receipt->bankFeedEntryId() !== null) {
                continue;
            }
            if ($receipt->status()->isDraft()) {
                continue;
            }
            if (!$this->emailMatches($entry->cardholderEmail(), $receipt, $profilesByUserId)) {
                continue;
            }
            if (strcasecmp($bankCurrency->toString(), $receipt->currency()->toString()) !== 0) {
                continue;
            }
            if (!$bankAmount->withinTolerance($receipt->totalAmount(), Module::MATCH_AMOUNT_TOLERANCE)) {
                continue;
            }
            if ($txDate === null || $receipt->receiptDate() === null) {
                continue;
            }
            if (!$this->withinDateDays($txDate, $receipt->receiptDate(), Module::MATCH_DATE_DAYS)) {
                continue;
            }
            $matches[] = $receipt;
        }

        return $matches;
    }

    /**
     * @param array<int, ExpenseProfile> $profilesByUserId
     */
    private function emailMatches(string $cardholderEmail, ExpenseReceipt $receipt, array $profilesByUserId): bool
    {
        $email = strtolower(trim($cardholderEmail));
        $ownerId = $receipt->ownerUserId()->toInt();
        $profile = $profilesByUserId[$ownerId] ?? null;
        if ($profile !== null && $profile->legacyEmail() === $email) {
            return true;
        }
        $createdBy = $receipt->legacy()->createdBy();
        if ($createdBy !== null && strtolower(trim($createdBy)) === $email) {
            return true;
        }

        return false;
    }

    private function withinDateDays(DateTimeImmutable $a, DateTimeImmutable $b, int $days): bool
    {
        $aDay = $a->setTime(0, 0);
        $bDay = $b->setTime(0, 0);
        $diff = (int) $aDay->diff($bDay)->format('%a');

        return $diff <= $days;
    }
}
