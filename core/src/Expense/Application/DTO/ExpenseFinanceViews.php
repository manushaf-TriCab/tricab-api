<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpensePeriod;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/**
 * Lightweight array views for finance application services.
 */
final class ExpenseFinanceViews
{
    /** @return array<string, mixed> */
    public static function bankEntry(ExpenseBankFeedEntry $entry): array
    {
        $name = trim(implode(' ', array_filter([$entry->firstName(), $entry->lastName()], static fn (?string $p): bool => $p !== null && $p !== '')));

        return [
            'publicId' => $entry->publicId()->toString(),
            'id' => $entry->id(),
            'bankReference' => $entry->bankReference(),
            'cardholderEmail' => $entry->cardholderEmail(),
            'cardholderUserId' => $entry->cardholderUserId()?->toInt(),
            'cardholderName' => $name !== '' ? $name : null,
            'country' => $entry->country(),
            'transactionDate' => $entry->transactionDate()?->format('Y-m-d'),
            'postingDate' => $entry->postingDate()?->format('Y-m-d'),
            'statementDate' => $entry->statementDate()?->format('Y-m-d'),
            'merchantName' => $entry->merchantName(),
            'merchantCity' => $entry->merchantCity(),
            'merchantCountry' => $entry->merchantCountry(),
            'amount' => $entry->amount()->amount(),
            'currency' => $entry->currency()->toString(),
            'foreignAmount' => $entry->foreignAmount()?->amount(),
            'foreignCurrency' => $entry->foreignCurrency()?->toString(),
            'transactionType' => $entry->transactionType(),
            'matchStatus' => $entry->matchStatus()->toString(),
            'receiptId' => $entry->receiptId(),
            'periodId' => $entry->periodId(),
            'resolution' => $entry->resolution()?->toString(),
            'resolutionNote' => $entry->resolutionNote(),
            'offsetBankFeedEntryId' => $entry->offsetBankFeedEntryId(),
            'rowVersion' => $entry->rowVersion()?->toBase64(),
        ];
    }

    /** @return array<string, mixed> */
    public static function period(ExpensePeriod $period): array
    {
        return [
            'publicId' => $period->publicId()->toString(),
            'id' => $period->id(),
            'country' => $period->country(),
            'periodStart' => $period->periodStart()->format('Y-m-d'),
            'periodEnd' => $period->periodEnd()->format('Y-m-d'),
            'transactionStartDate' => $period->transactionStartDate()?->format('Y-m-d'),
            'transactionEndDate' => $period->transactionEndDate()?->format('Y-m-d'),
            'status' => $period->status()->toString(),
            'closedAt' => $period->closedAt()?->format(DATE_ATOM),
            'closedByUserId' => $period->closedByUserId()?->toInt(),
            'createdAtUtc' => $period->createdAtUtc()->format(DATE_ATOM),
            'rowVersion' => $period->rowVersion()?->toBase64(),
        ];
    }

    /** @return array<string, mixed> */
    public static function receipt(ExpenseReceipt $receipt): array
    {
        return [
            'publicId' => $receipt->publicId()->toString(),
            'id' => $receipt->id()?->toInt(),
            'ownerUserId' => $receipt->ownerUserId()->toInt(),
            'submittedByUserId' => $receipt->submittedByUserId()?->toInt(),
            'managerUserId' => $receipt->managerUserId()?->toInt(),
            'vendorName' => $receipt->vendorName(),
            'totalAmount' => $receipt->totalAmount()->amount(),
            'gstAmount' => $receipt->gstAmount()->amount(),
            'currency' => $receipt->currency()->toString(),
            'receiptDate' => $receipt->receiptDate()?->format('Y-m-d'),
            'category' => $receipt->category()->rawValue(),
            'otherCategory' => $receipt->category()->otherCategory(),
            'costCentre' => $receipt->costCentre(),
            'description' => $receipt->description(),
            'status' => $receipt->status()->toString(),
            'managerStatus' => $receipt->managerStatus()->toString(),
            'bankFeedEntryId' => $receipt->bankFeedEntryId(),
            'periodId' => $receipt->periodId(),
            'resolution' => $receipt->resolution()?->toString(),
            'resolutionNote' => $receipt->resolutionNote(),
            'rowVersion' => $receipt->rowVersion()?->toBase64(),
        ];
    }

    /** @param list<string|null> $cells */
    public static function csvLine(array $cells): string
    {
        $escaped = [];
        foreach ($cells as $cell) {
            $value = (string) ($cell ?? '');
            if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, "\r")) {
                $escaped[] = '"' . str_replace('"', '""', $value) . '"';
            } else {
                $escaped[] = $value;
            }
        }

        return implode(',', $escaped);
    }
}
