<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

use TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceBankAssembler;
use TriCab\Core\Expense\Application\Service\LegacyExpenseFinancePeriodAssembler;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
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
            'needsReceipt' => LegacyExpenseFinanceBankAssembler::needsReceipt(
                $entry->matchStatus()->toString(),
                $entry->resolution()?->toString(),
            ),
            'rowVersion' => $entry->rowVersion()?->toBase64(),
            'source' => 'v2',
            'mutable' => true,
            'actions' => [
                'resolve' => true,
                'match' => true,
                'offset' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function period(ExpensePeriod $period): array
    {
        $periodStart = $period->periodStart()->format('Y-m-d');
        $periodEnd = $period->periodEnd()->format('Y-m-d');

        return [
            'publicId' => $period->publicId()->toString(),
            'id' => $period->id(),
            'country' => $period->country(),
            'label' => LegacyExpenseFinancePeriodAssembler::label($periodStart, $periodEnd, $period->country()),
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'transactionStartDate' => $period->transactionStartDate()?->format('Y-m-d'),
            'transactionEndDate' => $period->transactionEndDate()?->format('Y-m-d'),
            'status' => $period->status()->toString(),
            'closedAt' => $period->closedAt()?->format(DATE_ATOM),
            'closedBy' => null,
            'closedByUserId' => $period->closedByUserId()?->toInt(),
            'createdAtUtc' => $period->createdAtUtc()->format(DATE_ATOM),
            'rowVersion' => $period->rowVersion()?->toBase64(),
            'source' => 'v2',
            'mutable' => true,
        ];
    }

    /**
     * @param array{
     *   employeeDisplayName?: ?string,
     *   employeeEmail?: ?string,
     *   managerDisplayName?: ?string,
     *   managerLogin?: ?string,
     *   branch?: ?string,
     *   department?: ?string
     * } $enrichment
     * @return array<string, mixed>
     */
    public static function receipt(ExpenseReceipt $receipt, array $enrichment = []): array
    {
        $customer = null;
        if ($receipt->customerCode() !== null || $receipt->customerName() !== null) {
            $customer = [
                'customerCode' => $receipt->customerCode(),
                'customerName' => $receipt->customerName(),
                'customerState' => $receipt->customerState(),
            ];
        }

        $attachment = $receipt->attachment();

        return [
            'publicId' => $receipt->publicId()->toString(),
            'id' => $receipt->id()?->toInt(),
            'ownerUserId' => $receipt->ownerUserId()->toInt(),
            'submittedByUserId' => $receipt->submittedByUserId()?->toInt(),
            'managerUserId' => $receipt->managerUserId()?->toInt(),
            'employeeDisplayName' => $enrichment['employeeDisplayName'] ?? null,
            'employeeEmail' => $enrichment['employeeEmail'] ?? null,
            'managerDisplayName' => $enrichment['managerDisplayName'] ?? null,
            'managerLogin' => $enrichment['managerLogin'] ?? null,
            'branch' => $enrichment['branch'] ?? null,
            'department' => $enrichment['department'] ?? null,
            'vendorName' => $receipt->vendorName(),
            'totalAmount' => $receipt->totalAmount()->amount(),
            'gstAmount' => $receipt->gstAmount()->amount(),
            'gstTreatment' => $receipt->gstType(),
            'currency' => $receipt->currency()->toString(),
            'receiptDate' => $receipt->receiptDate()?->format('Y-m-d'),
            'submittedAt' => $receipt->submittedAt()?->format(DATE_ATOM),
            'createdAt' => $receipt->createdAt()->format(DATE_ATOM),
            'updatedAt' => $receipt->updatedAt()->format(DATE_ATOM),
            'category' => $receipt->category()->rawValue(),
            'categoryLabel' => $receipt->category()->rawValue(),
            'otherCategory' => $receipt->category()->otherCategory(),
            'costCentre' => $receipt->costCentre(),
            'customer' => $customer,
            'statementPeriod' => null,
            'description' => $receipt->description(),
            'status' => $receipt->status()->toString(),
            'managerStatus' => $receipt->managerStatus()->toString(),
            'managerReason' => $receipt->managerReason(),
            'bankFeedEntryId' => $receipt->bankFeedEntryId(),
            'periodId' => $receipt->periodId(),
            'resolution' => $receipt->resolution()?->toString(),
            'resolutionNote' => $receipt->resolutionNote(),
            'attachment' => $attachment === null ? null : [
                'available' => true,
                'fileName' => $attachment->fileName(),
                'contentType' => null,
            ],
            'rowVersion' => $receipt->rowVersion()?->toBase64(),
            'source' => 'v2',
            'mutable' => true,
            'actions' => [
                'resolve' => true,
                'match' => true,
                'previewAttachment' => $attachment !== null,
                'downloadAttachment' => $attachment !== null,
            ],
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

    /**
     * Build currency buckets from receipt views — never cross-sum currencies.
     *
     * @param list<array<string, mixed>> $receiptViews
     * @return list<array{currency: string, totalAmount: string, receiptCount: int}>
     */
    public static function currencyBuckets(array $receiptViews): array
    {
        $buckets = [];
        foreach ($receiptViews as $view) {
            $currencyRaw = $view['currency'] ?? 'AUD';
            $currency = strtoupper(MixedValueCoercion::stringValue($currencyRaw)) ?: 'AUD';
            if (!isset($buckets[$currency])) {
                $buckets[$currency] = ['total' => 0.0, 'count' => 0];
            }
            $buckets[$currency]['total'] += MixedValueCoercion::floatValue($view['totalAmount'] ?? 0);
            $buckets[$currency]['count']++;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $currency => $data) {
            $out[] = [
                'currency' => $currency,
                'totalAmount' => number_format($data['total'], 2, '.', ''),
                'receiptCount' => $data['count'],
            ];
        }

        return $out;
    }
}
