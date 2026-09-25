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
use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;
use TriCab\Core\Expense\Module;

/**
 * Employee-centric Finance workspace merging V2 Azure + legacy MySQL receipts.
 *
 * Profile resolution uses a single admin-query index (by userId / email / login).
 * Historical cardholders without a V2 ExpenseProfile still appear under their email.
 *
 * @phpstan-type ProfileIndexEntry array{
 *   userId: int,
 *   email: string|null,
 *   displayName: string|null,
 *   legacyLogin: string|null
 * }
 * @phpstan-type ProfileIndex array{
 *   byUserId: array<int, ProfileIndexEntry>,
 *   byEmail: array<string, ProfileIndexEntry>,
 *   byLogin: array<string, ProfileIndexEntry>
 * }
 * @phpstan-type FinanceEmployeeGroup array{
 *   key: string,
 *   userId: int|null,
 *   email: string|null,
 *   displayName: string|null,
 *   legacyLogin: string|null,
 *   branch: string|null,
 *   department: string|null,
 *   managerDisplayName: string|null,
 *   receipts: list<array<string, mixed>>,
 *   bankEntries: list<array<string, mixed>>,
 *   receiptCount?: int,
 *   bankCount?: int,
 *   stats?: array<string, int>,
 *   currencyTotals?: list<array{currency: string, totalAmount: string, receiptCount: int}>
 * }
 */
final class GetExpenseFinanceWorkspaceService
{
    /** Upper bound for the legacy bank slice loaded into employee groups. */
    private const LEGACY_BANK_CAP = 2000;

    private const UNMAPPED_CARDHOLDER_KEY = 'unmapped:cardholder';

    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpensePeriodRepository $periods,
        private readonly ExpenseProfileAdminQuery $profileAdminQuery,
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
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $status = null,
        ?string $search = null,
        ?string $currency = null,
        ?string $customer = null,
        ?string $branch = null,
        ?string $view = null,
        int $page = 1,
        int $pageSize = 50,
    ): array {
        $this->access->requireFinance($actor);

        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $branchFilter = $branch !== null && trim($branch) !== '' ? trim($branch) : null;
        $viewFilter = $view !== null ? strtolower(trim($view)) : 'all';
        if (!in_array($viewFilter, ['all', 'needs-action', 'done'], true)) {
            $viewFilter = 'all';
        }

        $period = null;
        $legacyPeriodView = null;
        $legacyPeriodId = null;
        $countryCode = $country !== null && trim($country) !== ''
            ? strtoupper(trim($country))
            : null;
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
            if (($dateFrom === null || trim($dateFrom) === '') && ($dateTo === null || trim($dateTo) === '')) {
                $dateFrom = MixedValueCoercion::nullableString($legacyPeriodView['transactionStartDate'] ?? null)
                    ?? MixedValueCoercion::nullableString($legacyPeriodView['periodStart'] ?? null);
                $dateTo = MixedValueCoercion::nullableString($legacyPeriodView['transactionEndDate'] ?? null)
                    ?? MixedValueCoercion::nullableString($legacyPeriodView['periodEnd'] ?? null);
            }
        } elseif ($periodPublicId !== null && trim($periodPublicId) !== '') {
            $period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString($periodPublicId));
            if ($period === null) {
                throw ExpensePeriodNotFound::forPublicId($periodPublicId);
            }
            $countryCode = $period->country();
            if (($dateFrom === null || trim($dateFrom) === '') && ($dateTo === null || trim($dateTo) === '')) {
                $dateFrom = ($period->transactionStartDate() ?? $period->periodStart())->format('Y-m-d');
                $dateTo = ($period->transactionEndDate() ?? $period->periodEnd())->format('Y-m-d');
            }
        }

        $from = $dateFrom !== null && trim($dateFrom) !== '' ? substr(trim($dateFrom), 0, 10) : null;
        $to = $dateTo !== null && trim($dateTo) !== '' ? substr(trim($dateTo), 0, 10) : null;

        $bankEntries = [];
        if ($period !== null && $period->id() !== null) {
            $bankEntries = $this->bankFeeds->listByPeriodId($period->id());
        } elseif ($countryCode !== null) {
            $bankEntries = $this->bankFeeds->listByCountry($countryCode);
        } else {
            $bankEntries = $this->bankFeeds->listAll();
        }

        if ($from !== null || $to !== null) {
            $bankEntries = array_values(array_filter(
                $bankEntries,
                static function ($entry) use ($from, $to): bool {
                    $tx = $entry->transactionDate()?->format('Y-m-d') ?? '';
                    if ($tx === '') {
                        return true;
                    }
                    if ($from !== null && $tx < $from) {
                        return false;
                    }
                    if ($to !== null && $tx > $to) {
                        return false;
                    }

                    return true;
                },
            ));
        }

        $v2Receipts = $this->loadV2Receipts($from, $to, $status, $search, $currency, $customer);
        $migratedLegacyIds = $this->collectMigratedLegacyIds($v2Receipts);

        $legacyRows = [];
        $legacyTotal = 0;
        $legacyCurrency = [];
        if ($this->legacyReadEnabled) {
            $filters = new LegacyExpenseFinanceFilters(
                dateFrom: $from,
                dateTo: $to,
                status: $status,
                search: $search,
                currency: $currency,
                customer: $customer,
                excludeDraft: true,
                limit: 1000,
                offset: 0,
            );
            $legacyRows = $this->legacyFinance->listFinance($filters);
            $legacyTotal = $this->legacyFinance->countFinance($filters);
            $legacyCurrency = $this->legacyFinance->sumByCurrency($filters);
        }

        $profileIndex = $this->buildProfileIndex();
        /** @var array<string, FinanceEmployeeGroup> $groups */
        $groups = [];

        foreach ($v2Receipts as $receipt) {
            $ownerId = $receipt->ownerUserId()->toInt();
            $owner = $profileIndex['byUserId'][$ownerId] ?? null;
            $email = $owner['email'] ?? null;
            $key = $email !== null && $email !== '' ? $email : 'user:' . $ownerId;
            $managerId = $receipt->managerUserId()?->toInt();
            $manager = $managerId !== null ? ($profileIndex['byUserId'][$managerId] ?? null) : null;
            $view = ExpenseFinanceViews::receipt($receipt, [
                'employeeDisplayName' => $owner['displayName'] ?? $email,
                'employeeEmail' => $email,
                'managerDisplayName' => $manager['displayName'] ?? $manager['email'] ?? null,
                'managerLogin' => $manager['legacyLogin'] ?? null,
                'branch' => null,
                'department' => null,
            ]);
            $this->addToGroup(
                $groups,
                $key,
                $view,
                $owner,
                $email,
                $owner['displayName'] ?? null,
            );
        }

        foreach ($legacyRows as $row) {
            $legacyId = MixedValueCoercion::intValue($row['id'] ?? 0);
            if ($legacyId > 0 && isset($migratedLegacyIds[$legacyId])) {
                continue;
            }
            $createdBy = strtolower(MixedValueCoercion::stringValue($row['created_by'] ?? 'unknown'));
            $owner = $this->resolveByCreatedBy($createdBy, $profileIndex);
            $ownerUserId = $owner['userId'] ?? null;
            $managerLogin = strtolower(MixedValueCoercion::stringValue($row['manager_login'] ?? ''));
            if ($managerLogin !== '' && str_contains($managerLogin, '@')) {
                $managerLogin = explode('@', $managerLogin)[0];
            }
            $manager = $managerLogin !== ''
                ? $this->resolveByLogin($managerLogin, $profileIndex)
                : null;
            $employeeDisplayName = MixedValueCoercion::nullableString($row['submitter_display_name'] ?? null)
                ?? ($owner['displayName'] ?? null);
            $view = $this->legacyAssembler->toFinanceView($row, [
                'ownerUserId' => $ownerUserId,
                'managerUserId' => $manager['userId'] ?? null,
                'employeeEmail' => $createdBy !== '' ? $createdBy : null,
                'employeeDisplayName' => $employeeDisplayName ?? ($createdBy !== '' ? $createdBy : 'Legacy employee'),
                'managerDisplayName' => MixedValueCoercion::nullableString($row['manager_display_name'] ?? null)
                    ?? ($manager['displayName'] ?? $manager['email'] ?? null),
            ]);
            $this->addToGroup(
                $groups,
                $createdBy !== '' ? $createdBy : 'unknown',
                $view,
                $owner,
                $createdBy !== '' ? $createdBy : null,
                $employeeDisplayName,
            );
        }

        $migratedBankIds = [];
        foreach ($bankEntries as $entry) {
            $legacyBankId = $entry->legacyBankFeedId();
            if ($legacyBankId !== null && $legacyBankId > 0) {
                $migratedBankIds[$legacyBankId] = true;
            }
            $email = strtolower(trim($entry->cardholderEmail()));
            $groupKey = $email !== '' ? $email : self::UNMAPPED_CARDHOLDER_KEY;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $this->groupForCardholder($email, $profileIndex);
            }
            $groups[$groupKey]['bankEntries'][] = ExpenseFinanceViews::bankEntry($entry);
        }

        $legacyBankTotal = 0;
        if ($this->legacyReadEnabled) {
            $bankFilters = new LegacyExpenseFinanceBankFilters(
                periodId: $legacyPeriodId,
                country: $countryCode,
                dateFrom: $from,
                dateTo: $to,
            );
            $legacyBankTotal = $this->legacyBank->countBank($bankFilters);
            $legacyBankRows = ListExpenseFinanceBankTransactionsService::pageRows(
                $this->legacyBank,
                $bankFilters,
                self::LEGACY_BANK_CAP,
            );
            foreach ($legacyBankRows as $row) {
                $legacyBankId = MixedValueCoercion::intValue($row['id'] ?? 0);
                if ($legacyBankId > 0 && isset($migratedBankIds[$legacyBankId])) {
                    continue;
                }
                $email = strtolower(MixedValueCoercion::stringValue($row['cardholder_email'] ?? ''));
                $groupKey = $email !== '' ? $email : self::UNMAPPED_CARDHOLDER_KEY;
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = $this->groupForCardholder($email, $profileIndex);
                }
                $cardholder = $email !== '' ? ($profileIndex['byEmail'][$email] ?? null) : null;
                try {
                    $groups[$groupKey]['bankEntries'][] = $this->legacyBankAssembler->toFinanceView($row, [
                        'cardholderUserId' => $cardholder['userId'] ?? null,
                    ]);
                } catch (\Throwable) {
                    // One malformed historical row must not fail the whole workspace.
                    continue;
                }
            }
        }

        $employees = [];
        $summary = [
            'employeesRequiringAttention' => 0,
            'employeesDone' => 0,
            'unmatchedReceipts' => 0,
            'unmatchedBank' => 0,
            'pendingManagerApprovals' => 0,
            'receiptTotal' => 0,
            'legacyReceiptTotal' => $legacyTotal,
            'v2ReceiptTotal' => count($v2Receipts),
            'legacyBankTotal' => $legacyBankTotal,
            'v2BankTotal' => count($bankEntries),
        ];
        /** @var array<string, int> $branchCounts */
        $branchCounts = [];

        foreach ($groups as $group) {
            $stats = $this->computeEmployeeStats($group['receipts'], $group['bankEntries']);
            $group['stats'] = $stats;
            $group['currencyTotals'] = ExpenseFinanceViews::currencyBuckets($group['receipts']);
            $group['bankCurrencyTotals'] = ExpenseFinanceViews::bankCurrencyBuckets($group['bankEntries']);
            $group['receiptCount'] = count($group['receipts']);
            $group['bankCount'] = count($group['bankEntries']);
            // Strip heavy arrays for list payload — detail endpoint loads them.
            unset($group['receipts'], $group['bankEntries']);
            $needsAttention = $stats['needsReceipt'] > 0
                || $stats['needsMatch'] > 0
                || $stats['pendingApproval'] > 0
                || $stats['exceptions'] > 0;
            if ($needsAttention) {
                $summary['employeesRequiringAttention']++;
            } else {
                $summary['employeesDone']++;
            }
            $summary['unmatchedReceipts'] += $stats['needsMatch'];
            $summary['unmatchedBank'] += $stats['needsReceipt'];
            $summary['pendingManagerApprovals'] += $stats['pendingApproval'];
            $summary['receiptTotal'] += $group['receiptCount'];
            $branchLabel = MixedValueCoercion::nullableString($group['branch'] ?? null) ?? 'Unknown';
            $branchCounts[$branchLabel] = ($branchCounts[$branchLabel] ?? 0) + 1;
            $employees[] = $group;
        }

        $branchFacets = [];
        foreach ($branchCounts as $label => $count) {
            $branchFacets[] = ['branch' => $label, 'count' => $count];
        }
        usort($branchFacets, static function (array $a, array $b): int {
            if ($a['branch'] === 'Unknown') {
                return 1;
            }
            if ($b['branch'] === 'Unknown') {
                return -1;
            }

            return $b['count'] <=> $a['count'];
        });

        usort($employees, static function (array $a, array $b): int {
            $ap = $a['stats']['pendingApproval'];
            $bp = $b['stats']['pendingApproval'];
            if ($ap !== $bp) {
                return $bp <=> $ap;
            }
            $an = $a['stats']['needsMatch'] + $a['stats']['needsReceipt'];
            $bn = $b['stats']['needsMatch'] + $b['stats']['needsReceipt'];
            if ($an !== $bn) {
                return $bn <=> $an;
            }

            return $b['receiptCount'] <=> $a['receiptCount'];
        });

        if ($branchFilter !== null) {
            $employees = array_values(array_filter(
                $employees,
                static function (array $row) use ($branchFilter): bool {
                    $label = MixedValueCoercion::nullableString($row['branch'] ?? null) ?? 'Unknown';

                    return strcasecmp($label, $branchFilter) === 0;
                },
            ));
        }

        if ($viewFilter === 'needs-action') {
            $employees = array_values(array_filter(
                $employees,
                static function (array $row): bool {
                    $stats = $row['stats'];

                    return $stats['needsReceipt'] > 0
                        || $stats['needsMatch'] > 0
                        || $stats['pendingApproval'] > 0
                        || $stats['exceptions'] > 0;
                },
            ));
        } elseif ($viewFilter === 'done') {
            $employees = array_values(array_filter(
                $employees,
                static function (array $row): bool {
                    $stats = $row['stats'];

                    return $stats['needsReceipt'] === 0
                        && $stats['needsMatch'] === 0
                        && $stats['pendingApproval'] === 0
                        && $stats['exceptions'] === 0;
                },
            ));
        }

        $totalEmployees = count($employees);
        $offset = ($page - 1) * $pageSize;
        $pageItems = array_slice($employees, $offset, $pageSize);

        $currencyTotals = ExpenseFinanceViews::currencyBuckets(
            array_merge(
                array_map(static fn (ExpenseReceipt $r): array => ExpenseFinanceViews::receipt($r), $v2Receipts),
                array_map(fn (array $row): array => $this->legacyAssembler->toFinanceView($row), $legacyRows),
            ),
        );
        // Prefer SQL currency buckets for legacy accuracy when available.
        if ($legacyCurrency !== []) {
            $mergedCurrency = [];
            foreach ($currencyTotals as $bucket) {
                $mergedCurrency[$bucket['currency']] = $bucket;
            }
            foreach ($legacyCurrency as $bucket) {
                $cur = $bucket['currency'];
                // Already included via assembler path for listed rows; keep SQL totals as authoritative for legacy slice.
                if (!isset($mergedCurrency[$cur])) {
                    $mergedCurrency[$cur] = $bucket;
                }
            }
            ksort($mergedCurrency);
            $currencyTotals = array_values($mergedCurrency);
        }

        return [
            'country' => $countryCode,
            'period' => $period !== null ? ExpenseFinanceViews::period($period) : $legacyPeriodView,
            'filters' => [
                'dateFrom' => $from,
                'dateTo' => $to,
                'status' => $status,
                'search' => $search,
                'currency' => $currency,
                'customer' => $customer,
                'branch' => $branchFilter,
                'view' => $viewFilter,
            ],
            'summary' => $summary,
            'currencyTotals' => $currencyTotals,
            'branchFacets' => $branchFacets,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalEmployees' => $totalEmployees,
                'totalPages' => (int) max(1, (int) ceil($totalEmployees / $pageSize)),
            ],
            'employees' => $pageItems,
            'legacyReadEnabled' => $this->legacyReadEnabled,
            'historical' => $this->legacyReadEnabled ? [
                'legacyBankFeed' => $this->legacyBank->countBankByPeriodCountryMatchResolution(),
                'legacyPeriods' => $this->legacyFinance->countPeriodsByCountryStatus(),
                'legacyStatusCounts' => $this->legacyFinance->countByStatus($from, $to),
            ] : null,
        ];
    }

    /**
     * @return list<ExpenseReceipt>
     */
    private function loadV2Receipts(
        ?string $from,
        ?string $to,
        ?string $status,
        ?string $search,
        ?string $currency,
        ?string $customer,
    ): array {
        if ($from !== null && $to !== null) {
            $pool = $this->receipts->listInReceiptDateRange(
                new \DateTimeImmutable($from),
                new \DateTimeImmutable($to),
                false,
            );
        } else {
            $pool = $this->receipts->listAll(false);
        }

        $out = [];
        foreach ($pool as $receipt) {
            if ($receipt->status()->isDraft()) {
                continue;
            }
            if ($status !== null && trim($status) !== '' && $receipt->status()->toString() !== strtolower(trim($status))) {
                continue;
            }
            if ($currency !== null && trim($currency) !== ''
                && $receipt->currency()->toString() !== strtoupper(trim($currency))) {
                continue;
            }
            if ($customer !== null && trim($customer) !== '') {
                $needle = strtolower(trim($customer));
                $hay = strtolower(($receipt->customerCode() ?? '') . ' ' . ($receipt->customerName() ?? ''));
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            if ($search !== null && trim($search) !== '') {
                $needle = strtolower(trim($search));
                $hay = strtolower(implode(' ', [
                    $receipt->vendorName(),
                    $receipt->description() ?? '',
                    $receipt->costCentre(),
                    (string) $receipt->ownerUserId()->toInt(),
                ]));
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $out[] = $receipt;
        }

        return $out;
    }

    /**
     * @param list<ExpenseReceipt> $receipts
     * @return array<int, true>
     */
    private function collectMigratedLegacyIds(array $receipts): array
    {
        $ids = [];
        foreach ($receipts as $receipt) {
            $legacyId = $receipt->legacy()->legacyReceiptId();
            if ($legacyId !== null && $legacyId > 0) {
                $ids[$legacyId] = true;
            }
        }

        return $ids;
    }

    /**
     * @return ProfileIndex
     */
    private function buildProfileIndex(): array
    {
        $byUserId = [];
        $byEmail = [];
        $byLogin = [];
        foreach ($this->profileAdminQuery->listActiveProfiles() as $row) {
            $userId = $row['userId'];
            if ($userId <= 0) {
                continue;
            }
            $email = strtolower(trim($row['email']));
            $login = strtolower(trim($row['legacyLogin']));
            $displayName = trim($row['displayName']);
            $entry = [
                'userId' => $userId,
                'email' => $email !== '' ? $email : null,
                'displayName' => $displayName !== '' ? $displayName : ($email !== '' ? $email : null),
                'legacyLogin' => $login !== '' ? $login : null,
            ];
            $byUserId[$userId] = $entry;
            if ($email !== '') {
                $byEmail[$email] = $entry;
            }
            if ($login !== '') {
                $byLogin[$login] = $entry;
            }
        }

        return [
            'byUserId' => $byUserId,
            'byEmail' => $byEmail,
            'byLogin' => $byLogin,
        ];
    }

    /**
     * @param ProfileIndex $index
     * @return ProfileIndexEntry|null
     */
    private function resolveByCreatedBy(string $createdBy, array $index): ?array
    {
        $createdBy = strtolower(trim($createdBy));
        if ($createdBy === '' || $createdBy === 'unknown') {
            return null;
        }
        if (str_contains($createdBy, '@')) {
            return $index['byEmail'][$createdBy]
                ?? $this->resolveByLogin(explode('@', $createdBy)[0], $index);
        }

        return $this->resolveByLogin($createdBy, $index)
            ?? ($index['byEmail'][$createdBy . '@tricab.com'] ?? null);
    }

    /**
     * @param ProfileIndex $index
     * @return ProfileIndexEntry|null
     */
    private function resolveByLogin(string $login, array $index): ?array
    {
        $login = strtolower(trim($login));
        if ($login === '') {
            return null;
        }

        return $index['byLogin'][$login] ?? null;
    }

    /**
     * @param array<string, FinanceEmployeeGroup> $groups
     * @param array<string, mixed> $view
     * @param ProfileIndexEntry|null $owner
     */
    private function addToGroup(
        array &$groups,
        string $key,
        array $view,
        ?array $owner,
        ?string $email,
        ?string $displayName,
    ): void {
        if (!isset($groups[$key])) {
            $groups[$key] = $this->emptyGroup($owner, $email, $displayName, $key);
        }
        $groups[$key]['receipts'][] = $view;
        $branch = MixedValueCoercion::nullableString($view['branch'] ?? null);
        if ($groups[$key]['branch'] === null && $branch !== null) {
            $groups[$key]['branch'] = $branch;
        }
        $department = MixedValueCoercion::nullableString($view['department'] ?? null);
        if ($groups[$key]['department'] === null && $department !== null) {
            $groups[$key]['department'] = $department;
        }
        $managerDisplayName = MixedValueCoercion::nullableString($view['managerDisplayName'] ?? null);
        if ($groups[$key]['managerDisplayName'] === null && $managerDisplayName !== null) {
            $groups[$key]['managerDisplayName'] = $managerDisplayName;
        }
    }

    /**
     * @param ProfileIndex $profileIndex
     * @return FinanceEmployeeGroup
     */
    private function groupForCardholder(string $email, array $profileIndex): array
    {
        if ($email === '') {
            return $this->emptyGroup(null, null, 'Unmapped cardholder', self::UNMAPPED_CARDHOLDER_KEY);
        }
        $entry = $profileIndex['byEmail'][$email] ?? null;

        return $this->emptyGroup(
            $entry,
            $email,
            $entry['displayName'] ?? $email,
            $email,
        );
    }

    /**
     * @param ProfileIndexEntry|null $owner
     * @return FinanceEmployeeGroup
     */
    private function emptyGroup(?array $owner, ?string $email, ?string $displayName, ?string $key = null): array
    {
        $resolvedEmail = $email ?? $owner['email'] ?? null;

        return [
            'key' => $key ?? $resolvedEmail ?? ($owner !== null ? 'user:' . $owner['userId'] : self::UNMAPPED_CARDHOLDER_KEY),
            'userId' => $owner['userId'] ?? null,
            'email' => $resolvedEmail,
            'displayName' => $displayName ?? $owner['displayName'] ?? $resolvedEmail ?? 'Legacy employee',
            'legacyLogin' => $owner['legacyLogin'] ?? null,
            'branch' => null,
            'department' => null,
            'managerDisplayName' => null,
            'receipts' => [],
            'bankEntries' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $receipts
     * @param list<array<string, mixed>> $bankEntries
     * @return array{
     *   matched: int,
     *   needsReceipt: int,
     *   needsMatch: int,
     *   pendingApproval: int,
     *   approved: int,
     *   rejected: int,
     *   exceptions: int,
     *   markedCash: int,
     *   chased: int
     * }
     */
    private function computeEmployeeStats(array $receipts, array $bankEntries): array
    {
        $matched = 0;
        $needsMatch = 0;
        $pendingApproval = 0;
        $approved = 0;
        $rejected = 0;
        $exceptions = 0;
        $markedCash = 0;
        $chased = 0;

        foreach ($receipts as $receipt) {
            $status = MixedValueCoercion::stringValue($receipt['status'] ?? '');
            $managerStatus = MixedValueCoercion::stringValue($receipt['managerStatus'] ?? '');
            $resolution = strtolower(MixedValueCoercion::stringValue($receipt['resolution'] ?? ''));
            $bankId = $receipt['bankFeedEntryId'] ?? null;

            if ($status === 'approved' || $managerStatus === 'approved') {
                $approved++;
            }
            if ($status === 'rejected' || $managerStatus === 'rejected') {
                $rejected++;
            }
            if ($status === 'pending' || $managerStatus === 'pending') {
                $pendingApproval++;
            }
            if ($resolution === Module::RESOLUTION_MARKED_CASH) {
                $markedCash++;
                $exceptions++;
                continue;
            }
            if ($resolution === Module::RESOLUTION_EXCEPTION || $resolution === Module::RESOLUTION_FORCE_REJECTED) {
                $exceptions++;
                continue;
            }
            if ($resolution === Module::RESOLUTION_CHASED) {
                $chased++;
            }
            if ($bankId !== null && $bankId !== '') {
                $matched++;
                continue;
            }
            if ($status !== 'draft' && ($resolution === '' || $resolution === Module::RESOLUTION_CHASED)) {
                $needsMatch++;
            }
        }

        $needsReceipt = 0;
        foreach ($bankEntries as $entry) {
            if (($entry['needsReceipt'] ?? false) === true) {
                $needsReceipt++;
            }
        }

        return [
            'matched' => $matched,
            'needsReceipt' => $needsReceipt,
            'needsMatch' => $needsMatch,
            'pendingApproval' => $pendingApproval,
            'approved' => $approved,
            'rejected' => $rejected,
            'exceptions' => $exceptions,
            'markedCash' => $markedCash,
            'chased' => $chased,
        ];
    }

}
