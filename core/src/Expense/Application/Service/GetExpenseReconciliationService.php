<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceFilters;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;
use TriCab\Core\Expense\Module;

final class GetExpenseReconciliationService
{
    /** Upper bound for the legacy bank slice pulled into reconciliation. */
    private const LEGACY_BANK_CAP = 2000;

    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpensePeriodRepository $periods,
        private readonly ExpenseProfileRepository $profiles,
        private readonly LegacyExpenseFinanceReadRepository $legacyFinance,
        private readonly LegacyExpenseFinanceReceiptAssembler $legacyAssembler,
        private readonly LegacyExpenseFinanceBankReadRepository $legacyBank,
        private readonly LegacyExpenseFinanceBankAssembler $legacyBankAssembler,
        private readonly LegacyExpenseFinancePeriodAssembler $legacyPeriodAssembler,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ExpenseActor $actor,
        ?string $country = null,
        ?string $periodPublicId = null,
    ): array {
        $this->access->requireFinance($actor);

        $countryCode = $country !== null && trim($country) !== ''
            ? strtoupper(trim($country))
            : null;

        $period = null;
        $legacyPeriodView = null;
        $legacyPeriodId = null;
        $legacyPeriodRef = $periodPublicId !== null ? LegacyExpensePeriodRef::tryParse($periodPublicId) : null;
        if ($legacyPeriodRef !== null) {
            $periodRow = $this->legacyReadEnabled
                ? $this->legacyBank->findPeriodById($legacyPeriodRef->legacyPeriodId())
                : null;
            if ($periodRow === null) {
                throw ExpensePeriodNotFound::forPublicId($legacyPeriodRef->toPublicId());
            }
            $legacyPeriodId = $legacyPeriodRef->legacyPeriodId();
            $legacyPeriodView = $this->legacyPeriodAssembler->toFinanceView($periodRow);
            $countryCode = MixedValueCoercion::nullableString($legacyPeriodView['country'] ?? null) ?? $countryCode;
        } elseif ($periodPublicId !== null && trim($periodPublicId) !== '') {
            $period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString($periodPublicId));
            if ($period === null) {
                throw ExpensePeriodNotFound::forPublicId($periodPublicId);
            }
            $countryCode = $period->country();
        }

        $bankEntries = [];
        if ($period !== null && $period->id() !== null) {
            $bankEntries = $this->bankFeeds->listByPeriodId($period->id());
        } elseif ($countryCode !== null) {
            $bankEntries = $this->bankFeeds->listByCountry($countryCode);
        } else {
            $bankEntries = $this->bankFeeds->listAll();
        }

        $unmatchedBank = [];
        $matched = 0;
        $ambiguous = 0;
        $resolved = 0;
        $bankTotal = count($bankEntries);
        $migratedBankIds = [];
        foreach ($bankEntries as $entry) {
            $legacyBankId = $entry->legacyBankFeedId();
            if ($legacyBankId !== null && $legacyBankId > 0) {
                $migratedBankIds[$legacyBankId] = true;
            }
            if ($entry->matchStatus()->isMatched()) {
                $matched++;
            } elseif ($entry->matchStatus()->isAmbiguous()) {
                $ambiguous++;
            }
            if ($entry->resolution() !== null) {
                $resolved++;
            }
            if (
                !$entry->matchStatus()->isMatched()
                && ($entry->resolution() === null || $entry->resolution()->isChased())
            ) {
                $unmatchedBank[] = ExpenseFinanceViews::bankEntry($entry);
            }
        }

        $from = null;
        $to = null;
        if ($period !== null) {
            $txStart = $period->transactionStartDate() ?? $period->periodStart();
            $txEnd = $period->transactionEndDate() ?? $period->periodEnd();
            $from = $txStart->format('Y-m-d');
            $to = $txEnd->format('Y-m-d');
            $receiptPool = $this->receipts->listInReceiptDateRange($txStart, $txEnd, false);
        } else {
            if ($legacyPeriodView !== null) {
                $from = MixedValueCoercion::nullableString($legacyPeriodView['transactionStartDate'] ?? null)
                    ?? MixedValueCoercion::nullableString($legacyPeriodView['periodStart'] ?? null);
                $to = MixedValueCoercion::nullableString($legacyPeriodView['transactionEndDate'] ?? null)
                    ?? MixedValueCoercion::nullableString($legacyPeriodView['periodEnd'] ?? null);
            }
            $receiptPool = $this->receipts->listAll(false);
        }

        $migratedLegacyIds = [];
        $unmatchedReceipts = [];
        /** @var array<int, \TriCab\Core\Expense\Domain\Entity\ExpenseProfile|null> $profileCache */
        $profileCache = [];
        foreach ($receiptPool as $receipt) {
            $legacyId = $receipt->legacy()->legacyReceiptId();
            if ($legacyId !== null && $legacyId > 0) {
                $migratedLegacyIds[$legacyId] = true;
            }
            if ($receipt->bankFeedEntryId() !== null) {
                continue;
            }
            if ($receipt->status()->isDraft()) {
                continue;
            }
            $resolution = $receipt->resolution()?->toString();
            if ($resolution !== null && $resolution !== Module::RESOLUTION_CHASED) {
                continue;
            }
            $ownerId = $receipt->ownerUserId()->toInt();
            if (!array_key_exists($ownerId, $profileCache)) {
                $profileCache[$ownerId] = $this->profiles->findByUserId($receipt->ownerUserId());
            }
            $profile = $profileCache[$ownerId];
            $manager = null;
            if ($receipt->managerUserId() !== null) {
                $managerId = $receipt->managerUserId()->toInt();
                if (!array_key_exists($managerId, $profileCache)) {
                    $profileCache[$managerId] = $this->profiles->findByUserId($receipt->managerUserId());
                }
                $manager = $profileCache[$managerId];
            }
            $unmatchedReceipts[] = ExpenseFinanceViews::receipt($receipt, [
                'employeeEmail' => $profile?->legacyEmail(),
                'employeeDisplayName' => $profile?->legacyEmail(),
                'managerDisplayName' => $manager?->legacyEmail(),
                'managerLogin' => $manager?->legacyLogin(),
            ]);
        }

        if ($this->legacyReadEnabled) {
            $legacyFilters = new LegacyExpenseFinanceFilters(
                dateFrom: $from,
                dateTo: $to,
                unmatchedOnly: true,
                excludeDraft: true,
                limit: 1000,
                offset: 0,
            );
            foreach ($this->legacyFinance->listFinance($legacyFilters) as $row) {
                $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
                if ($legacyId > 0 && isset($migratedLegacyIds[$legacyId])) {
                    continue;
                }
                $unmatchedReceipts[] = $this->legacyAssembler->toFinanceView($row);
            }
        }

        if ($this->legacyReadEnabled) {
            $bankRows = ListExpenseFinanceBankTransactionsService::pageRows(
                $this->legacyBank,
                new LegacyExpenseFinanceBankFilters(
                    periodId: $legacyPeriodId,
                    country: $countryCode,
                    dateFrom: $from,
                    dateTo: $to,
                ),
                self::LEGACY_BANK_CAP,
            );
            foreach ($bankRows as $row) {
                $legacyBankId = MixedValueCoercion::intValue($row['id'] ?? 0);
                if ($legacyBankId > 0 && isset($migratedBankIds[$legacyBankId])) {
                    continue;
                }
                try {
                    $view = $this->legacyBankAssembler->toFinanceView($row);
                } catch (\Throwable) {
                    continue;
                }
                $bankTotal++;
                $matchStatus = MixedValueCoercion::stringValue($view['matchStatus'] ?? '');
                if ($matchStatus === 'matched') {
                    $matched++;
                } elseif ($matchStatus === 'ambiguous') {
                    $ambiguous++;
                }
                $resolution = MixedValueCoercion::nullableString($view['resolution'] ?? null);
                if ($resolution !== null) {
                    $resolved++;
                }
                if ($view['needsReceipt'] === true) {
                    $unmatchedBank[] = $view;
                }
            }
        }

        return [
            'country' => $countryCode,
            'period' => $period !== null ? ExpenseFinanceViews::period($period) : $legacyPeriodView,
            'counts' => [
                'bankTotal' => $bankTotal,
                'matched' => $matched,
                'ambiguous' => $ambiguous,
                'resolved' => $resolved,
                'unmatchedBank' => count($unmatchedBank),
                'unmatchedReceipts' => count($unmatchedReceipts),
            ],
            'currencyTotals' => ExpenseFinanceViews::currencyBuckets($unmatchedReceipts),
            'unmatchedBank' => $unmatchedBank,
            'unmatchedReceipts' => $unmatchedReceipts,
            'legacyReadEnabled' => $this->legacyReadEnabled,
        ];
    }
}
