<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;

final class ExportFinanceReconciliationCsvService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpensePeriodRepository $periods,
    ) {
    }

    public function execute(
        ExpenseActor $actor,
        ?string $country = null,
        ?string $periodPublicId = null,
    ): string {
        $this->access->requireFinance($actor);

        $headers = [
            'Transaction Date',
            'Posting Date',
            'Cardholder',
            'Cardholder Email',
            'Department',
            'Branch',
            'Cost Centre',
            'Merchant',
            'Merchant City',
            'Merchant Country',
            'Bank Reference',
            'Country',
            'Line Type',
            'Amount',
            'Currency',
            'Foreign Amount',
            'Foreign Currency',
            'Receipt Status',
            'Match Status',
            'Resolution',
            'Receipt ID',
            'Receipt Vendor',
            'Receipt Date',
            'Receipt Amount',
            'Receipt GST',
            'Category Code',
            'Category Name',
            'Approval Status',
        ];

        $lines = [ExpenseFinanceViews::csvLine($headers)];
        $categories = ExpenseCatalog::categories();

        $entries = [];
        if ($periodPublicId !== null && trim($periodPublicId) !== '') {
            $period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString($periodPublicId));
            if ($period === null || $period->id() === null) {
                throw ExpensePeriodNotFound::forPublicId($periodPublicId);
            }
            $entries = $this->bankFeeds->listByPeriodId($period->id());
        } elseif ($country !== null && trim($country) !== '') {
            $entries = $this->bankFeeds->listByCountry(strtoupper(trim($country)));
        } else {
            $entries = $this->bankFeeds->listAll();
        }

        foreach ($entries as $entry) {
            $receipt = null;
            if ($entry->receiptId() !== null) {
                $receipt = $this->receipts->findById($entry->receiptId());
            }

            $cardholder = trim(implode(' ', array_filter(
                [$entry->firstName(), $entry->lastName()],
                static fn (?string $p): bool => $p !== null && $p !== '',
            )));
            $categoryCode = $receipt?->category()->rawValue() ?? '';
            $categoryName = $categoryCode !== ''
                ? ($categories[$categoryCode] ?? $receipt?->category()->otherCategory() ?? $categoryCode)
                : '';

            $lines[] = ExpenseFinanceViews::csvLine([
                $entry->transactionDate()?->format('Y-m-d'),
                $entry->postingDate()?->format('Y-m-d'),
                $cardholder,
                $entry->cardholderEmail(),
                '',
                '',
                $receipt?->costCentre() ?? '',
                $entry->merchantName(),
                $entry->merchantCity(),
                $entry->merchantCountry(),
                $entry->bankReference(),
                $entry->country(),
                $entry->isPaymentType() ? 'Card Repayment' : 'Spend',
                $entry->amount()->amount(),
                $entry->currency()->toString(),
                $entry->foreignAmount()?->amount(),
                $entry->foreignCurrency()?->toString(),
                $receipt?->status()->toString() ?? '',
                $entry->matchStatus()->toString(),
                $entry->resolution()?->toString() ?? '',
                $receipt?->publicId()->toString() ?? '',
                $receipt?->vendorName() ?? '',
                $receipt?->receiptDate()?->format('Y-m-d') ?? '',
                $receipt?->totalAmount()->amount() ?? '',
                $receipt?->gstAmount()->amount() ?? '',
                $categoryCode,
                $categoryName,
                $receipt?->managerStatus()->toString() ?? '',
            ]);
        }

        return implode("\n", $lines) . "\n";
    }
}
