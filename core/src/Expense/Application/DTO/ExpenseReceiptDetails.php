<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

final class ExpenseReceiptDetails
{
    /**
     * @param array<string, mixed>|null $attachment
     * @param array<string, mixed>|null $customer
     */
    public function __construct(
        public readonly string $publicId,
        public readonly string $vendorName,
        public readonly string $totalAmount,
        public readonly string $gstAmount,
        public readonly string $currency,
        public readonly string $gstType,
        public readonly ?string $receiptDate,
        public readonly string $category,
        public readonly ?string $otherCategory,
        public readonly string $costCentre,
        public readonly ?string $description,
        public readonly string $status,
        public readonly string $managerStatus,
        public readonly ?string $managerReason,
        public readonly ?array $attachment,
        public readonly ?array $customer,
        public readonly string $createdAt,
        public readonly ?string $submittedAt,
        public readonly string $updatedAt,
    ) {
    }
}