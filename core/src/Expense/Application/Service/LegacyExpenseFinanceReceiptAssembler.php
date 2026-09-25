<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;

/**
 * Maps a legacy MySQL finance receipt row into the shared Finance receipt view.
 */
final class LegacyExpenseFinanceReceiptAssembler
{
    /**
     * @param array<string, mixed> $row
     * @param array{
     *   ownerUserId?: ?int,
     *   managerUserId?: ?int,
     *   employeeEmail?: ?string,
     *   employeeDisplayName?: ?string,
     *   managerDisplayName?: ?string
     * } $enrichment
     * @return array<string, mixed>
     */
    public function toFinanceView(array $row, array $enrichment = []): array
    {
        $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
        $publicId = LegacyExpenseReceiptRef::fromLegacyId($legacyId)->toPublicId();

        $fileUrl = MixedValueCoercion::stringValue($row['file_url'] ?? '');
        $fileName = MixedValueCoercion::stringValue($row['file_name'] ?? '');
        $hasAttachment = $fileUrl !== '';

        $customerCode = MixedValueCoercion::nullableString($row['customer_code'] ?? null);
        $customerName = MixedValueCoercion::nullableString($row['customer_name'] ?? null);
        $customer = null;
        if ($customerCode !== null || $customerName !== null) {
            $customer = [
                'customerCode' => $customerCode,
                'customerName' => $customerName,
                'customerState' => MixedValueCoercion::nullableString($row['customer_state'] ?? null),
            ];
        }

        $status = strtolower(MixedValueCoercion::stringValue($row['status'] ?? 'draft')) ?: 'draft';
        $managerStatus = strtolower(MixedValueCoercion::stringValue($row['manager_status'] ?? ''));
        if ($managerStatus === '') {
            $managerStatus = $status === 'pending' ? 'pending' : ($status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : 'none'));
        }

        $resolution = MixedValueCoercion::nullableString($row['resolution'] ?? null);
        $bankFeedId = MixedValueCoercion::nullableInt($row['bank_feed_id'] ?? null);
        if ($bankFeedId !== null && $bankFeedId <= 0) {
            $bankFeedId = null;
        }
        $periodId = MixedValueCoercion::nullableInt($row['period_id'] ?? null);
        if ($periodId !== null && $periodId <= 0) {
            $periodId = null;
        }

        $createdBy = MixedValueCoercion::nullableString($row['created_by'] ?? null);
        $managerLogin = MixedValueCoercion::nullableString($row['manager_login'] ?? null);

        return [
            'publicId' => $publicId,
            'id' => $legacyId > 0 ? $legacyId : null,
            'ownerUserId' => $enrichment['ownerUserId'] ?? null,
            'submittedByUserId' => null,
            'managerUserId' => $enrichment['managerUserId'] ?? null,
            'employeeDisplayName' => $enrichment['employeeDisplayName']
                ?? MixedValueCoercion::nullableString($row['submitter_display_name'] ?? null),
            'employeeEmail' => $enrichment['employeeEmail'] ?? $createdBy,
            'managerDisplayName' => $enrichment['managerDisplayName']
                ?? MixedValueCoercion::nullableString($row['manager_display_name'] ?? null)
                ?? $managerLogin,
            'managerLogin' => $managerLogin,
            'branch' => MixedValueCoercion::nullableString($row['submitter_branch'] ?? null),
            'department' => MixedValueCoercion::nullableString($row['submitter_department'] ?? null),
            'vendorName' => MixedValueCoercion::stringValue($row['vendor_name'] ?? ''),
            'totalAmount' => $this->decimalString($row['total_amount'] ?? '0'),
            'gstAmount' => $this->decimalString($row['gst_amount'] ?? '0'),
            'gstTreatment' => MixedValueCoercion::stringValue($row['gst_type'] ?? 'unknown') ?: 'unknown',
            'currency' => strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD',
            'receiptDate' => $this->dateOnly($row['receipt_date'] ?? null),
            'submittedAt' => null,
            'createdAt' => $this->atomOrFallback($row['created_at'] ?? null),
            'updatedAt' => $this->atomOrFallback($row['updated_at'] ?? null, $this->atomOrFallback($row['created_at'] ?? null)),
            'category' => MixedValueCoercion::stringValue($row['category'] ?? ''),
            'categoryLabel' => MixedValueCoercion::stringValue($row['category'] ?? ''),
            'otherCategory' => MixedValueCoercion::nullableString($row['other_category'] ?? null),
            'costCentre' => MixedValueCoercion::stringValue($row['cost_centre'] ?? ''),
            'customer' => $customer,
            'statementPeriod' => MixedValueCoercion::nullableString($row['statement_period'] ?? null),
            'description' => MixedValueCoercion::nullableString($row['description'] ?? null),
            'status' => $status,
            'managerStatus' => $managerStatus,
            'managerReason' => MixedValueCoercion::nullableString($row['manager_reason'] ?? null),
            'bankFeedEntryId' => $bankFeedId,
            'periodId' => $periodId,
            'resolution' => $resolution,
            'resolutionNote' => MixedValueCoercion::nullableString($row['resolution_note'] ?? null),
            'attachment' => $hasAttachment ? [
                'available' => true,
                'fileName' => $fileName !== '' ? $fileName : null,
                'contentType' => null,
            ] : null,
            'rowVersion' => null,
            'source' => 'legacy',
            'mutable' => false,
            'actions' => [
                'resolve' => false,
                'match' => false,
                'previewAttachment' => $hasAttachment,
                'downloadAttachment' => $hasAttachment,
            ],
        ];
    }

    private function decimalString(mixed $value): string
    {
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return '0.00';
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

    private function atomOrFallback(mixed $value, ?string $fallback = null): string
    {
        if ($value === null || $value === '') {
            return $fallback ?? (new DateTimeImmutable('now'))->format(DATE_ATOM);
        }
        try {
            return (new DateTimeImmutable(MixedValueCoercion::stringValue($value)))->format(DATE_ATOM);
        } catch (\Throwable) {
            return $fallback ?? (new DateTimeImmutable('now'))->format(DATE_ATOM);
        }
    }
}
