<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TriExpense;

use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;

final class TriExpenseDtoSerializer
{
    /** @return array<string, mixed> */
    public static function receipt(ExpenseReceiptDetails $details): array
    {
        return [
            'publicId' => $details->publicId,
            'vendorName' => $details->vendorName,
            'totalAmount' => $details->totalAmount,
            'gstAmount' => $details->gstAmount,
            'currency' => $details->currency,
            'gstType' => $details->gstType,
            'receiptDate' => $details->receiptDate,
            'category' => $details->category,
            'otherCategory' => $details->otherCategory,
            'costCentre' => $details->costCentre,
            'description' => $details->description,
            'status' => $details->status,
            'managerStatus' => $details->managerStatus,
            'managerReason' => $details->managerReason,
            'attachment' => $details->attachment,
            'customer' => $details->customer,
            'createdAt' => $details->createdAt,
            'submittedAt' => $details->submittedAt,
            'updatedAt' => $details->updatedAt,
            'deletedAt' => $details->deletedAt,
            'rowVersion' => $details->rowVersion,
        ];
    }

    /**
     * @param list<ExpenseReceiptDetails> $items
     * @return list<array<string, mixed>>
     */
    public static function receipts(array $items): array
    {
        return array_map(static fn (ExpenseReceiptDetails $d): array => self::receipt($d), $items);
    }
}