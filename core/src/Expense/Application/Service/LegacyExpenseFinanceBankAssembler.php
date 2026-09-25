<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseBankFeedRef;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Expense\Module;

/**
 * Maps a legacy MySQL tc.triexpense_bank_feed row into the shared Finance bank entry view.
 */
final class LegacyExpenseFinanceBankAssembler
{
    /**
     * @param array<string, mixed> $row
     * @param array{
     *   cardholderUserId?: ?int,
     *   matchedReceiptPublicId?: ?string,
     *   periodLabel?: ?string
     * } $enrichment
     * @return array<string, mixed>
     */
    public function toFinanceView(array $row, array $enrichment = []): array
    {
        $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
        $publicId = LegacyExpenseBankFeedRef::fromLegacyId($legacyId)->toPublicId();

        $first = MixedValueCoercion::stringValue($row['first_name'] ?? '');
        $last = MixedValueCoercion::stringValue($row['last_name'] ?? '');
        $cardholderName = trim($first . ' ' . $last);

        $matchStatus = strtolower(MixedValueCoercion::stringValue($row['match_status'] ?? '')) ?: 'unmatched';
        $resolution = MixedValueCoercion::nullableString($row['resolution'] ?? null);
        if ($resolution !== null) {
            $resolution = strtolower($resolution);
        }

        $receiptId = $this->positiveIntOrNull($row['receipt_id'] ?? null);
        $matchedReceiptPublicId = $enrichment['matchedReceiptPublicId'] ?? null;
        if ($matchedReceiptPublicId === null && $receiptId !== null) {
            $matchedReceiptPublicId = LegacyExpenseReceiptRef::fromLegacyId($receiptId)->toPublicId();
        }

        $offsetId = $this->positiveIntOrNull($row['offset_tx_id'] ?? null);
        $periodId = $this->positiveIntOrNull($row['period_id'] ?? null);

        return [
            'publicId' => $publicId,
            'id' => $legacyId > 0 ? $legacyId : null,
            'bankReference' => MixedValueCoercion::stringValue($row['bank_reference'] ?? ''),
            'cardholderEmail' => strtolower(MixedValueCoercion::stringValue($row['cardholder_email'] ?? '')),
            'cardholderUserId' => $enrichment['cardholderUserId'] ?? null,
            'cardholderName' => $cardholderName !== '' ? $cardholderName : null,
            'country' => strtoupper(MixedValueCoercion::stringValue($row['country'] ?? '')) ?: null,
            'transactionDate' => $this->dateOnly($row['transaction_date'] ?? null),
            'postingDate' => $this->dateOnly($row['posting_date'] ?? null),
            'statementDate' => $this->dateOnly($row['statement_date'] ?? null),
            'merchantName' => MixedValueCoercion::stringValue($row['merchant_name'] ?? ''),
            'merchantCity' => MixedValueCoercion::nullableString($row['merchant_city'] ?? null),
            'merchantCountry' => MixedValueCoercion::nullableString($row['merchant_country'] ?? null),
            'merchantPostcode' => MixedValueCoercion::nullableString($row['merchant_postcode'] ?? null),
            'merchantCategoryGroup' => MixedValueCoercion::nullableString($row['merchant_category_group'] ?? null),
            'amount' => $this->decimalString($row['amount'] ?? '0'),
            'currency' => strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD',
            'foreignAmount' => $this->nullableDecimalString($row['foreign_amount'] ?? null),
            'foreignCurrency' => $this->nullableCurrency($row['foreign_currency'] ?? null),
            'netAmount' => $this->nullableDecimalString($row['net_amount'] ?? null),
            'taxAmount' => $this->nullableDecimalString($row['tax_amount'] ?? null),
            'transactionType' => MixedValueCoercion::nullableString($row['transaction_type'] ?? null),
            'matchStatus' => $matchStatus,
            'receiptId' => $receiptId,
            'matchedReceiptPublicId' => $matchedReceiptPublicId,
            'periodId' => $periodId,
            'periodPublicId' => $periodId !== null
                ? LegacyExpensePeriodRef::fromLegacyId($periodId)->toPublicId()
                : null,
            'periodLabel' => $enrichment['periodLabel'] ?? null,
            'resolution' => $resolution,
            'resolutionNote' => MixedValueCoercion::nullableString($row['resolution_note'] ?? null),
            'resolvedBy' => MixedValueCoercion::nullableString($row['resolved_by'] ?? null),
            'resolvedAt' => $this->atomOrNull($row['resolved_at'] ?? null),
            'offsetBankFeedEntryId' => $offsetId,
            'offsetBankFeedPublicId' => $offsetId !== null
                ? LegacyExpenseBankFeedRef::fromLegacyId($offsetId)->toPublicId()
                : null,
            'uploadedAt' => $this->atomOrNull($row['uploaded_at'] ?? null),
            'uploadedBy' => MixedValueCoercion::nullableString($row['uploaded_by'] ?? null),
            'needsReceipt' => self::needsReceipt($matchStatus, $resolution),
            'rowVersion' => null,
            'source' => 'legacy',
            'mutable' => false,
            'actions' => [
                'resolve' => false,
                'match' => false,
                'offset' => false,
            ],
        ];
    }

    /**
     * Legacy te_bf "needs receipt" semantics: unmatched/ambiguous and not yet written off.
     */
    public static function needsReceipt(string $matchStatus, ?string $resolution): bool
    {
        if (!in_array(strtolower($matchStatus), ['unmatched', 'ambiguous'], true)) {
            return false;
        }
        $normalized = $resolution === null ? '' : strtolower(trim($resolution));

        return $normalized === '' || $normalized === Module::RESOLUTION_CHASED;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $id = MixedValueCoercion::nullableInt($value);

        return $id !== null && $id > 0 ? $id : null;
    }

    private function decimalString(mixed $value): string
    {
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return '0.00';
    }

    private function nullableDecimalString(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableCurrency(mixed $value): ?string
    {
        $raw = MixedValueCoercion::nullableString($value);

        return $raw === null ? null : strtoupper($raw);
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = MixedValueCoercion::stringValue($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m) === 1) {
            return substr($m[0], 0, 10);
        }
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function atomOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable(MixedValueCoercion::stringValue($value)))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
