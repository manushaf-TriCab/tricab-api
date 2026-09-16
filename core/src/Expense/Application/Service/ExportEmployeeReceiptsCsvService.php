<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ExportEmployeeReceiptsCsvService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseProfileRepository $profiles,
    ) {
    }

    public function execute(ExpenseActor $actor, int $ownerUserId): string
    {
        $this->access->requireFinance($actor);

        $owner = UserId::fromInt($ownerUserId);
        $headers = [
            'ID',
            'Date',
            'Vendor',
            'Category Code',
            'Category Description',
            'Amount',
            'GST',
            'Currency',
            'Cost Centre',
            'Submitted By',
            'Manager',
            'Manager Status',
            'Status',
            'Description',
        ];

        $lines = [ExpenseFinanceViews::csvLine($headers)];
        $categories = ExpenseCatalog::categories();

        foreach ($this->receipts->listOwnedBy($owner, false) as $receipt) {
            $code = $receipt->category()->rawValue();
            $description = $categories[$code]
                ?? $receipt->category()->otherCategory()
                ?? $code;

            $submittedBy = '';
            if ($receipt->submittedByUserId() !== null) {
                $submittedBy = $this->profiles->findByUserId($receipt->submittedByUserId())?->legacyEmail()
                    ?? (string) $receipt->submittedByUserId()->toInt();
            }

            $manager = '';
            if ($receipt->managerUserId() !== null) {
                $manager = $this->profiles->findByUserId($receipt->managerUserId())?->legacyEmail()
                    ?? (string) $receipt->managerUserId()->toInt();
            }

            $lines[] = ExpenseFinanceViews::csvLine([
                $receipt->publicId()->toString(),
                $receipt->receiptDate()?->format('Y-m-d'),
                $receipt->vendorName(),
                $code,
                $description,
                $receipt->totalAmount()->amount(),
                $receipt->gstAmount()->amount(),
                $receipt->currency()->toString(),
                $receipt->costCentre(),
                $submittedBy,
                $manager,
                $receipt->managerStatus()->toString(),
                $receipt->status()->toString(),
                $receipt->description(),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }
}
