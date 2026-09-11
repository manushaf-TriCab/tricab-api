<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

final class ExpenseReceiptAssembler
{
    public function toDetails(ExpenseReceipt $receipt): ExpenseReceiptDetails
    {
        $attachment = null;
        if ($receipt->attachment() !== null) {
            $ref = $receipt->attachment();
            $attachment = [
                'storageSource' => $ref->storageSource(),
                'fileName' => $ref->fileName(),
                'dualReadClass' => $ref->dualReadClass(),
            ];
        }

        $customer = null;
        if ($receipt->customerCode() !== null || $receipt->customerName() !== null) {
            $customer = [
                'customerCode' => $receipt->customerCode(),
                'customerName' => $receipt->customerName(),
                'customerState' => $receipt->customerState(),
                'contactName' => $receipt->contactName(),
                'contactEmail' => $receipt->contactEmail(),
                'contactPhone' => $receipt->contactPhone(),
            ];
        }

        return new ExpenseReceiptDetails(
            publicId: $receipt->publicId()->toString(),
            vendorName: $receipt->vendorName(),
            totalAmount: $receipt->totalAmount()->amount(),
            gstAmount: $receipt->gstAmount()->amount(),
            currency: $receipt->currency()->toString(),
            gstType: $receipt->gstType(),
            receiptDate: $receipt->receiptDate()?->format('Y-m-d'),
            category: $receipt->category()->rawValue(),
            otherCategory: $receipt->category()->otherCategory(),
            costCentre: $receipt->costCentre(),
            description: $receipt->description(),
            status: $receipt->status()->toString(),
            managerStatus: $receipt->managerStatus()->toString(),
            managerReason: $receipt->managerReason(),
            attachment: $attachment,
            customer: $customer,
            createdAt: $receipt->createdAt()->format(DATE_ATOM),
            submittedAt: $receipt->submittedAt()?->format(DATE_ATOM),
            updatedAt: $receipt->updatedAt()->format(DATE_ATOM),
            deletedAt: $receipt->deletedAt()?->format(DATE_ATOM),
            rowVersion: $receipt->rowVersion()?->toBase64(),
        );
    }
}