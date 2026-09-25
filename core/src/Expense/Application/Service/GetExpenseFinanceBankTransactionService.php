<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseBankFeedRef;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;

/**
 * Finance bank transaction detail for both V2 Azure entries and legacy MySQL rows.
 */
final class GetExpenseFinanceBankTransactionService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly LegacyExpenseFinanceBankReadRepository $legacyBank,
        private readonly LegacyExpenseFinanceReadRepository $legacyFinance,
        private readonly LegacyExpenseFinanceBankAssembler $legacyAssembler,
        private readonly LegacyExpenseFinancePeriodAssembler $legacyPeriodAssembler,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $publicId): array
    {
        $this->access->requireFinance($actor);

        $legacyRef = LegacyExpenseBankFeedRef::tryParse($publicId);
        if ($legacyRef !== null) {
            return $this->legacyDetail($legacyRef);
        }

        $entry = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($publicId));
        if ($entry === null) {
            throw ExpenseBankFeedNotFound::forPublicId($publicId);
        }

        $view = ExpenseFinanceViews::bankEntry($entry);
        $view['matchedReceipt'] = null;
        $receiptId = $entry->receiptId();
        if ($receiptId !== null) {
            $receipt = $this->receipts->findById($receiptId);
            if ($receipt !== null) {
                $view['matchedReceipt'] = [
                    'publicId' => $receipt->publicId()->toString(),
                    'vendorName' => $receipt->vendorName(),
                    'totalAmount' => $receipt->totalAmount()->amount(),
                    'currency' => $receipt->currency()->toString(),
                    'receiptDate' => $receipt->receiptDate()?->format('Y-m-d'),
                    'source' => 'v2',
                ];
            }
        }
        $view['matchedReceiptPublicId'] = MixedValueCoercion::nullableString($view['matchedReceipt']['publicId'] ?? null);

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyDetail(LegacyExpenseBankFeedRef $ref): array
    {
        $row = $this->legacyReadEnabled ? $this->legacyBank->findBankById($ref->legacyBankFeedId()) : null;
        if ($row === null) {
            throw ExpenseBankFeedNotFound::forPublicId($ref->toPublicId());
        }

        $legacyReceiptId = MixedValueCoercion::nullableInt($row['receipt_id'] ?? null);
        if ($legacyReceiptId !== null && $legacyReceiptId <= 0) {
            $legacyReceiptId = null;
        }

        $matchedReceipt = $legacyReceiptId !== null ? $this->matchedReceipt($legacyReceiptId) : null;
        $periodId = MixedValueCoercion::nullableInt($row['period_id'] ?? null);
        $periodView = null;
        if ($periodId !== null && $periodId > 0) {
            $periodRow = $this->legacyBank->findPeriodById($periodId);
            if ($periodRow !== null) {
                $periodView = $this->legacyPeriodAssembler->toFinanceView($periodRow);
            }
        }

        $view = $this->legacyAssembler->toFinanceView($row, [
            'matchedReceiptPublicId' => MixedValueCoercion::nullableString($matchedReceipt['publicId'] ?? null),
            'periodLabel' => MixedValueCoercion::nullableString($periodView['label'] ?? null),
        ]);
        $view['matchedReceipt'] = $matchedReceipt;
        $view['period'] = $periodView;

        return $view;
    }

    /**
     * Prefers the migrated Azure receipt when the legacy receipt has already been imported.
     *
     * @return array<string, mixed>|null
     */
    private function matchedReceipt(int $legacyReceiptId): ?array
    {
        foreach ($this->receipts->listAll(false) as $receipt) {
            if ($receipt->legacy()->legacyReceiptId() !== $legacyReceiptId) {
                continue;
            }

            return [
                'publicId' => $receipt->publicId()->toString(),
                'vendorName' => $receipt->vendorName(),
                'totalAmount' => $receipt->totalAmount()->amount(),
                'currency' => $receipt->currency()->toString(),
                'receiptDate' => $receipt->receiptDate()?->format('Y-m-d'),
                'source' => 'v2',
            ];
        }

        $row = $this->legacyFinance->findById($legacyReceiptId);
        if ($row === null) {
            return null;
        }

        return [
            'publicId' => LegacyExpenseReceiptRef::fromLegacyId($legacyReceiptId)->toPublicId(),
            'vendorName' => MixedValueCoercion::stringValue($row['vendor_name'] ?? ''),
            'totalAmount' => number_format(MixedValueCoercion::floatValue($row['total_amount'] ?? 0), 2, '.', ''),
            'currency' => strtoupper(MixedValueCoercion::stringValue($row['currency'] ?? 'AUD')) ?: 'AUD',
            'receiptDate' => substr(MixedValueCoercion::stringValue($row['receipt_date'] ?? ''), 0, 10) ?: null,
            'source' => 'legacy',
        ];
    }
}
