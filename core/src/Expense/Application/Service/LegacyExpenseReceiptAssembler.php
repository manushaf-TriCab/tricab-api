<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;

/**
 * Maps a legacy MySQL receipt row into the shared ExpenseReceiptDetails DTO.
 */
final class LegacyExpenseReceiptAssembler
{
    /**
     * @param array<string, mixed> $row
     */
    public function toDetails(array $row): ExpenseReceiptDetails
    {
        $legacyId = (int) ($row['id'] ?? 0);
        $publicId = LegacyExpenseReceiptRef::fromLegacyId($legacyId)->toPublicId();

        $fileUrl = trim((string) ($row['file_url'] ?? ''));
        $fileName = trim((string) ($row['file_name'] ?? ''));
        $attachment = null;
        if ($fileUrl !== '') {
            $ref = ExpenseAttachmentRef::fromLegacyUrl($fileUrl, $fileName !== '' ? $fileName : null);
            $attachment = [
                'storageSource' => $ref->storageSource(),
                'fileName' => $ref->fileName(),
                'dualReadClass' => $ref->dualReadClass(),
            ];
        }

        $customerCode = $this->nullableString($row['customer_code'] ?? null);
        $customerName = $this->nullableString($row['customer_name'] ?? null);
        $customer = null;
        if ($customerCode !== null || $customerName !== null) {
            $customer = [
                'customerCode' => $customerCode,
                'customerName' => $customerName,
                'customerState' => $this->nullableString($row['customer_state'] ?? null),
                'contactName' => $this->nullableString($row['contact_name'] ?? null),
                'contactEmail' => $this->nullableString($row['contact_email'] ?? null),
                'contactPhone' => $this->nullableString($row['contact_phone'] ?? null),
            ];
        }

        $receiptDate = $this->dateOnly($row['receipt_date'] ?? null);
        $createdAt = $this->atomOrFallback($row['created_at'] ?? null);
        $updatedAt = $this->atomOrFallback($row['updated_at'] ?? null, $createdAt);

        $status = strtolower(trim((string) ($row['status'] ?? 'draft')));
        if ($status === '') {
            $status = 'draft';
        }
        $managerStatus = strtolower(trim((string) ($row['manager_status'] ?? '')));
        if ($managerStatus === '') {
            $managerStatus = $status === 'pending' ? 'pending' : ($status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : 'none'));
        }

        return new ExpenseReceiptDetails(
            publicId: $publicId,
            vendorName: trim((string) ($row['vendor_name'] ?? '')),
            totalAmount: $this->decimalString($row['total_amount'] ?? '0'),
            gstAmount: $this->decimalString($row['gst_amount'] ?? '0'),
            currency: strtoupper(trim((string) ($row['currency'] ?? 'AUD'))) ?: 'AUD',
            gstType: trim((string) ($row['gst_type'] ?? 'unknown')) ?: 'unknown',
            receiptDate: $receiptDate,
            category: trim((string) ($row['category'] ?? '')),
            otherCategory: $this->nullableString($row['other_category'] ?? null),
            costCentre: trim((string) ($row['cost_centre'] ?? '')),
            description: $this->nullableString($row['description'] ?? null),
            status: $status,
            managerStatus: $managerStatus,
            managerReason: $this->nullableString($row['manager_reason'] ?? null),
            attachment: $attachment,
            customer: $customer,
            createdAt: $createdAt,
            submittedAt: null,
            updatedAt: $updatedAt,
            deletedAt: null,
            rowVersion: null,
            source: ExpenseReceiptDetails::SOURCE_LEGACY,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
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
        $raw = trim((string) $value);
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
            return (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return $fallback ?? (new DateTimeImmutable('now'))->format(DATE_ATOM);
        }
    }
}
