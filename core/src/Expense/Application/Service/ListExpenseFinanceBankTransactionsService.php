<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;

/**
 * Finance bank transaction list merging V2 Azure entries with legacy MySQL rows.
 *
 * A legacy period selection stays inside MySQL; a V2 period selection stays inside Azure.
 * With no period the two sources are merged and deduped on legacyBankFeedId.
 */
final class ListExpenseFinanceBankTransactionsService
{
    public const MAX_PAGE_SIZE = 200;

    /** Upper bound for the merged in-memory legacy slice. */
    private const LEGACY_MERGE_CAP = 2000;

    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpensePeriodRepository $periods,
        private readonly LegacyExpenseFinanceBankReadRepository $legacyBank,
        private readonly LegacyExpenseFinanceBankAssembler $legacyAssembler,
        private readonly LegacyExpenseFinancePeriodAssembler $legacyPeriodAssembler,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ExpenseActor $actor,
        ?string $periodPublicId = null,
        ?string $country = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $currency = null,
        ?string $matchStatus = null,
        ?string $resolution = null,
        ?string $cardholderEmail = null,
        ?string $search = null,
        ?bool $needsReceiptOnly = null,
        int $page = 1,
        int $pageSize = 50,
    ): array {
        $this->access->requireFinance($actor);

        $page = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));

        $countryCode = self::trimmedOrNull($country);
        $countryCode = $countryCode !== null ? strtoupper($countryCode) : null;
        $from = self::dateOrNull($dateFrom);
        $to = self::dateOrNull($dateTo);

        $legacyRef = $periodPublicId !== null ? LegacyExpensePeriodRef::tryParse($periodPublicId) : null;
        $v2Period = null;
        $periodView = null;

        if ($legacyRef !== null) {
            $periodRow = $this->legacyReadEnabled
                ? $this->legacyBank->findPeriodById($legacyRef->legacyPeriodId())
                : null;
            if ($periodRow === null) {
                throw ExpensePeriodNotFound::forPublicId($legacyRef->toPublicId());
            }
            $periodView = $this->legacyPeriodAssembler->toFinanceView($periodRow);
            $countryCode = MixedValueCoercion::nullableString($periodView['country'] ?? null) ?? $countryCode;
        } elseif (self::trimmedOrNull($periodPublicId) !== null) {
            $v2Period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString((string) $periodPublicId));
            if ($v2Period === null) {
                throw ExpensePeriodNotFound::forPublicId((string) $periodPublicId);
            }
            $periodView = ExpenseFinanceViews::period($v2Period);
            $countryCode = $v2Period->country();
        }

        $legacyFilters = new LegacyExpenseFinanceBankFilters(
            periodId: $legacyRef?->legacyPeriodId(),
            country: $countryCode,
            dateFrom: $from,
            dateTo: $to,
            currency: self::trimmedOrNull($currency),
            matchStatus: self::trimmedOrNull($matchStatus),
            resolution: self::trimmedOrNull($resolution),
            cardholderEmail: self::trimmedOrNull($cardholderEmail),
            search: self::trimmedOrNull($search),
            needsReceiptOnly: $needsReceiptOnly,
        );

        // Legacy-only selection: page and total straight from MySQL.
        if ($legacyRef !== null) {
            $offset = ($page - 1) * $pageSize;
            $rows = $this->legacyBank->listBank($legacyFilters->withWindow($pageSize, $offset));
            $items = array_map(fn (array $row): array => $this->legacyAssembler->toFinanceView($row), $rows);
            $total = $this->legacyBank->countBank($legacyFilters);

            return $this->payload(
                $items,
                $total,
                $this->legacyBank->sumBankByCurrency($legacyFilters),
                $page,
                $pageSize,
                $periodView,
                v2Count: 0,
                legacyCount: $total,
                truncated: false,
            );
        }

        $v2Views = [];
        $migratedLegacyIds = [];
        foreach ($this->loadV2Entries($v2Period?->id(), $countryCode) as $entry) {
            $legacyId = $entry->legacyBankFeedId();
            if ($legacyId !== null && $legacyId > 0) {
                $migratedLegacyIds[$legacyId] = true;
            }
            if (!$this->matchesV2Filters($entry, $from, $to, $currency, $matchStatus, $resolution, $cardholderEmail, $search, $needsReceiptOnly)) {
                continue;
            }
            $v2Views[] = ExpenseFinanceViews::bankEntry($entry);
        }

        $legacyViews = [];
        $legacyTotal = 0;
        $truncated = false;
        if ($this->legacyReadEnabled && $v2Period === null) {
            $legacyTotal = $this->legacyBank->countBank($legacyFilters);
            $truncated = $legacyTotal > self::LEGACY_MERGE_CAP;
            foreach ($this->pageLegacyRows($legacyFilters, self::LEGACY_MERGE_CAP) as $row) {
                $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
                if ($legacyId > 0 && isset($migratedLegacyIds[$legacyId])) {
                    continue;
                }
                $legacyViews[] = $this->legacyAssembler->toFinanceView($row);
            }
        }

        $merged = array_merge($v2Views, $legacyViews);
        usort($merged, static function (array $a, array $b): int {
            $da = MixedValueCoercion::stringValue($a['transactionDate'] ?? '');
            $db = MixedValueCoercion::stringValue($b['transactionDate'] ?? '');
            if ($da !== $db) {
                return $db <=> $da;
            }

            return MixedValueCoercion::intValue($b['id'] ?? 0) <=> MixedValueCoercion::intValue($a['id'] ?? 0);
        });

        $total = count($merged);
        $items = array_slice($merged, ($page - 1) * $pageSize, $pageSize);

        return $this->payload(
            $items,
            $total,
            self::currencyBuckets($merged),
            $page,
            $pageSize,
            $periodView,
            v2Count: count($v2Views),
            legacyCount: count($legacyViews),
            truncated: $truncated,
        );
    }

    /**
     * Currency buckets for bank amounts — never cross-currency summed.
     *
     * @param list<array<string, mixed>> $views
     * @return list<array{currency: string, totalAmount: string, entryCount: int}>
     */
    public static function currencyBuckets(array $views): array
    {
        $buckets = [];
        foreach ($views as $view) {
            $currency = strtoupper(MixedValueCoercion::stringValue($view['currency'] ?? 'AUD')) ?: 'AUD';
            if (!isset($buckets[$currency])) {
                $buckets[$currency] = ['total' => 0.0, 'count' => 0];
            }
            $buckets[$currency]['total'] += MixedValueCoercion::floatValue($view['amount'] ?? 0);
            $buckets[$currency]['count']++;
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $currency => $data) {
            $out[] = [
                'currency' => $currency,
                'totalAmount' => number_format($data['total'], 2, '.', ''),
                'entryCount' => $data['count'],
            ];
        }

        return $out;
    }

    /**
     * Pages through the legacy repository so the 500-row filter cap is never exceeded per query.
     *
     * @return list<array<string, mixed>>
     */
    public static function pageRows(
        LegacyExpenseFinanceBankReadRepository $repository,
        LegacyExpenseFinanceBankFilters $filters,
        int $maxRows,
    ): array {
        $out = [];
        $offset = 0;
        while (count($out) < $maxRows) {
            $window = min(LegacyExpenseFinanceBankFilters::MAX_LIMIT, $maxRows - count($out));
            $rows = $repository->listBank($filters->withWindow($window, $offset));
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $out[] = $row;
            }
            if (count($rows) < $window) {
                break;
            }
            $offset += $window;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pageLegacyRows(LegacyExpenseFinanceBankFilters $filters, int $maxRows): array
    {
        return self::pageRows($this->legacyBank, $filters, $maxRows);
    }

    /**
     * @return list<ExpenseBankFeedEntry>
     */
    private function loadV2Entries(?int $periodId, ?string $country): array
    {
        if ($periodId !== null) {
            return $this->bankFeeds->listByPeriodId($periodId);
        }
        if ($country !== null) {
            return $this->bankFeeds->listByCountry($country);
        }

        return $this->bankFeeds->listAll();
    }

    private function matchesV2Filters(
        ExpenseBankFeedEntry $entry,
        ?string $from,
        ?string $to,
        ?string $currency,
        ?string $matchStatus,
        ?string $resolution,
        ?string $cardholderEmail,
        ?string $search,
        ?bool $needsReceiptOnly,
    ): bool {
        $tx = $entry->transactionDate()?->format('Y-m-d');
        if ($from !== null && $tx !== null && $tx < $from) {
            return false;
        }
        if ($to !== null && $tx !== null && $tx > $to) {
            return false;
        }
        $wantCurrency = self::trimmedOrNull($currency);
        if ($wantCurrency !== null && $entry->currency()->toString() !== strtoupper($wantCurrency)) {
            return false;
        }
        $wantMatch = self::trimmedOrNull($matchStatus);
        if ($wantMatch !== null && $entry->matchStatus()->toString() !== strtolower($wantMatch)) {
            return false;
        }
        $entryResolution = $entry->resolution()?->toString();
        $wantResolution = self::trimmedOrNull($resolution);
        if ($wantResolution !== null) {
            $want = strtolower($wantResolution);
            if ($want === 'none' || $want === 'unresolved') {
                if ($entryResolution !== null) {
                    return false;
                }
            } elseif ($entryResolution !== $want) {
                return false;
            }
        }
        $wantEmail = self::trimmedOrNull($cardholderEmail);
        if ($wantEmail !== null && $entry->cardholderEmail() !== strtolower($wantEmail)) {
            return false;
        }
        if ($needsReceiptOnly === true
            && !LegacyExpenseFinanceBankAssembler::needsReceipt($entry->matchStatus()->toString(), $entryResolution)) {
            return false;
        }
        $needle = self::trimmedOrNull($search);
        if ($needle !== null) {
            $hay = strtolower(implode(' ', [
                $entry->merchantName(),
                $entry->cardholderEmail(),
                $entry->bankReference(),
                $entry->merchantCity() ?? '',
            ]));
            if (!str_contains($hay, strtolower($needle))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array{currency: string, totalAmount: string, entryCount: int}> $currencyTotals
     * @param array<string, mixed>|null $periodView
     * @return array<string, mixed>
     */
    private function payload(
        array $items,
        int $total,
        array $currencyTotals,
        int $page,
        int $pageSize,
        ?array $periodView,
        int $v2Count,
        int $legacyCount,
        bool $truncated,
    ): array {
        return [
            'items' => $items,
            'total' => $total,
            'currencyTotals' => $currencyTotals,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => (int) max(1, (int) ceil($total / $pageSize)),
            'period' => $periodView,
            'counts' => [
                'v2' => $v2Count,
                'legacy' => $legacyCount,
            ],
            'legacyTruncated' => $truncated,
            'legacyReadEnabled' => $this->legacyReadEnabled,
        ];
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function dateOrNull(?string $value): ?string
    {
        $trimmed = self::trimmedOrNull($value);

        return $trimmed === null ? null : substr($trimmed, 0, 10);
    }
}
