<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Legacy\InMemoryLegacyExpenseFinanceBankReadRepository;
use TriCab\Api\Expense\Legacy\InMemoryLegacyExpenseFinanceReadRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseBankFeedRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpensePeriodRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\NullExpenseNotificationPort;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\GetExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\GetExpenseFinanceBankTransactionService;
use TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceBankAssembler;
use TriCab\Core\Expense\Application\Service\LegacyExpenseFinancePeriodAssembler;
use TriCab\Core\Expense\Application\Service\ListExpenseFinanceBankTransactionsService;
use TriCab\Core\Expense\Application\Service\ListExpensePeriodsService;
use TriCab\Core\Expense\Application\Service\ResolveExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\UndoOffsetExpenseBankFeedService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseBankFeedRef;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpensePeriodRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

final class ExpenseFinanceBankLegacyParityTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseBankFeedRepository $bankFeeds;
    private InMemoryExpensePeriodRepository $periods;
    private ExpenseAccessService $access;
    private ExpenseActor $accessActor;
    private ExpenseActor $financeActor;

    protected function setUp(): void
    {
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->bankFeeds = new InMemoryExpenseBankFeedRepository();
        $this->periods = new InMemoryExpensePeriodRepository();
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());

        $alice = UserId::fromInt(101);
        $finance = UserId::fromInt(303);
        $this->profiles->save(new ExpenseProfile($alice, 'alice', 'alice@tricab.com', true, false, false, 'mgr', null));
        $this->profiles->save(new ExpenseProfile($finance, 'finance', 'finance@tricab.com', true, true, false, null, null));

        $this->accessActor = new ExpenseActor($alice, 'alice-pub', 'alice@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $this->financeActor = new ExpenseActor(
            $finance,
            'fin-pub',
            'finance@tricab.com',
            [Module::PERMISSION_ACCESS, Module::PERMISSION_FINANCE],
            ['employee'],
        );
    }

    public function test_accessCannotListLegacyBankTransactions(): void
    {
        $this->expectException(ExpenseAccessDenied::class);
        $this->listService($this->legacyRepo())->execute($this->accessActor);
    }

    public function test_accessCannotReadLegacyBankDetail(): void
    {
        $this->expectException(ExpenseAccessDenied::class);
        $this->detailService($this->legacyRepo())->execute($this->accessActor, 'legacy-bank:1');
    }

    public function test_accessCannotListMergedPeriods(): void
    {
        $this->expectException(ExpenseAccessDenied::class);
        $this->periodsService($this->legacyRepo())->execute($this->accessActor);
    }

    public function test_financeListsLegacyBankTransactions(): void
    {
        $result = $this->listService($this->legacyRepo())->execute($this->financeActor);
        self::assertSame(4, $result['total']);
        self::assertTrue($result['legacyReadEnabled']);
        $items = self::rows($result, 'items');
        self::assertSame(['legacy', 'legacy', 'legacy', 'legacy'], array_column($items, 'source'));
        self::assertSame('legacy-bank:4', $items[0]['publicId']);
    }

    public function test_legacyReadDisabledHidesLegacyBank(): void
    {
        $result = $this->listService($this->legacyRepo(), false)->execute($this->financeActor);
        self::assertSame(0, $result['total']);
        self::assertFalse($result['legacyReadEnabled']);
    }

    public function test_financeReadsLegacyBankDetailWithLegacyReceiptLink(): void
    {
        $view = $this->detailService($this->legacyRepo())->execute($this->financeActor, 'legacy-bank:1');
        self::assertSame('legacy-bank:1', $view['publicId']);
        self::assertSame('legacy', $view['source']);
        self::assertFalse($view['mutable']);
        self::assertFalse(self::obj($view, 'actions')['resolve']);
        self::assertSame('legacy:9001', $view['matchedReceiptPublicId']);
        $matchedReceipt = self::obj($view, 'matchedReceipt');
        self::assertSame('Legacy Cafe', $matchedReceipt['vendorName']);
        self::assertSame('legacy', $matchedReceipt['source']);
    }

    public function test_unknownLegacyBankDetailIsNotFound(): void
    {
        $this->expectException(ExpenseBankFeedNotFound::class);
        $this->detailService($this->legacyRepo())->execute($this->financeActor, 'legacy-bank:99999');
    }

    public function test_getBankFeedServiceRoutesLegacyIds(): void
    {
        $detail = $this->detailService($this->legacyRepo());
        $service = new GetExpenseBankFeedService($this->access, $this->bankFeeds, $detail);
        $view = $service->execute($this->financeActor, 'legacy-bank:2');
        self::assertSame('legacy-bank:2', $view['publicId']);
        self::assertFalse($view['mutable']);
    }

    public function test_filtersAndPagination(): void
    {
        $service = $this->listService($this->legacyRepo());

        $au = $service->execute($this->financeActor, null, 'AU');
        self::assertSame(3, $au['total']);

        $unmatched = $service->execute($this->financeActor, null, null, null, null, null, 'unmatched');
        self::assertSame(2, $unmatched['total']);

        $byEmail = $service->execute($this->financeActor, null, null, null, null, null, null, null, 'alice@tricab.com');
        self::assertSame(3, $byEmail['total']);

        $page2 = $service->execute($this->financeActor, null, null, null, null, null, null, null, null, null, null, 2, 2);
        self::assertSame(4, $page2['total']);
        $page2Items = self::rows($page2, 'items');
        self::assertCount(2, $page2Items);
        self::assertSame(2, $page2['totalPages']);
        self::assertSame('legacy-bank:2', $page2Items[0]['publicId']);
    }

    public function test_needsReceiptClassification(): void
    {
        $result = $this->listService($this->legacyRepo())
            ->execute($this->financeActor, null, null, null, null, null, null, null, null, null, true);

        // id 2 unmatched/no resolution and id 3 ambiguous/chased qualify; id 4 is written_off.
        self::assertSame(2, $result['total']);
        $items = self::rows($result, 'items');
        self::assertSame(['legacy-bank:3', 'legacy-bank:2'], array_column($items, 'publicId'));
        foreach ($items as $item) {
            self::assertTrue($item['needsReceipt']);
        }
    }

    public function test_currencyBucketsNeverCrossSum(): void
    {
        $result = $this->listService($this->legacyRepo())->execute($this->financeActor);
        $buckets = self::indexBy(self::rows($result, 'currencyTotals'), 'currency');
        self::assertSame(['AUD', 'NZD'], array_keys($buckets));
        self::assertSame('60.00', $buckets['AUD']['totalAmount']);
        self::assertSame(3, $buckets['AUD']['entryCount']);
        self::assertSame('40.00', $buckets['NZD']['totalAmount']);
        self::assertSame(1, $buckets['NZD']['entryCount']);
    }

    public function test_legacyPeriodSelectionStaysInLegacy(): void
    {
        $result = $this->listService($this->legacyRepo())->execute($this->financeActor, 'legacy-period:2');
        self::assertSame(2, $result['total']);
        self::assertSame('legacy-period:2', self::obj($result, 'period')['publicId']);
        self::assertSame(0, self::obj($result, 'counts')['v2']);
        foreach (self::rows($result, 'items') as $item) {
            self::assertSame('legacy-period:2', $item['periodPublicId']);
        }
    }

    public function test_periodListIncludesOpenLegacyPeriods(): void
    {
        $byId = self::indexBy($this->periodsService($this->legacyRepo())->execute($this->financeActor), 'publicId');
        self::assertArrayHasKey('legacy-period:2', $byId);
        self::assertArrayHasKey('legacy-period:3', $byId);
        self::assertSame('open', $byId['legacy-period:2']['status']);
        self::assertSame('legacy', $byId['legacy-period:2']['source']);
        self::assertFalse($byId['legacy-period:2']['mutable']);
        self::assertSame('May–Jul 2026 — Australia', $byId['legacy-period:2']['label']);
        self::assertSame('2026-05-04', $byId['legacy-period:2']['transactionStartDate']);
    }

    public function test_periodListExcludesLegacyWhenDisabled(): void
    {
        $items = $this->periodsService($this->legacyRepo(), false)->execute($this->financeActor);
        self::assertSame([], $items);
    }

    public function test_offsetPartnerExposedAsLegacyPublicId(): void
    {
        $view = $this->detailService($this->legacyRepo())->execute($this->financeActor, 'legacy-bank:4');
        self::assertSame('legacy-bank:2', $view['offsetBankFeedPublicId']);
        self::assertSame(2, $view['offsetBankFeedEntryId']);
    }

    public function test_mutationsBlockedOnLegacyBankIds(): void
    {
        $undo = new UndoOffsetExpenseBankFeedService($this->access, $this->bankFeeds, new InMemoryExpenseAuditRecorder());
        $this->expectException(ExpenseLegacyReadOnly::class);
        $undo->execute($this->financeActor, 'legacy-bank:2', 'rv');
    }

    public function test_resolveBlockedOnLegacyBankIds(): void
    {
        $resolve = new ResolveExpenseBankFeedService(
            $this->access,
            $this->bankFeeds,
            new NullExpenseNotificationPort(),
            new InMemoryExpenseAuditRecorder(),
            FixedClock::utc('2026-09-14T00:00:00+00:00'),
        );
        $this->expectException(ExpenseLegacyReadOnly::class);
        $resolve->execute($this->financeActor, 'legacy-bank:2', Module::RESOLUTION_CHASED, null, 'rv');
    }

    public function test_legacyRefsRejectMutation(): void
    {
        LegacyExpenseBankFeedRef::assertNotLegacy('550e8400-e29b-41d4-a716-446655440000');
        LegacyExpensePeriodRef::assertNotLegacy('550e8400-e29b-41d4-a716-446655440000');

        $this->expectException(ExpenseLegacyReadOnly::class);
        LegacyExpensePeriodRef::assertNotLegacy('legacy-period:2');
    }

    public function test_legacyBankRefRoundTrips(): void
    {
        self::assertSame('legacy-bank:17', LegacyExpenseBankFeedRef::fromLegacyId(17)->toPublicId());
        self::assertSame(17, (LegacyExpenseBankFeedRef::tryParse('LEGACY-BANK:17')?->legacyBankFeedId()));
        self::assertNull(LegacyExpenseBankFeedRef::tryParse('legacy:17'));
        self::assertNull(LegacyExpensePeriodRef::tryParse('legacy-bank:17'));
    }

    public function test_repositoryHonoursLimitCapAndOffset(): void
    {
        $repo = $this->legacyRepo();
        $filters = new LegacyExpenseFinanceBankFilters(limit: 5000);
        self::assertSame(LegacyExpenseFinanceBankFilters::MAX_LIMIT, $filters->normalizedLimit());
        self::assertCount(4, $repo->listBank($filters));
        self::assertCount(1, $repo->listBank($filters->withWindow(1, 1)));
        self::assertSame(4, $repo->countBank(new LegacyExpenseFinanceBankFilters()));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function rows(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function obj(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function indexBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = $row[$key] ?? null;
            self::assertIsString($id);
            $out[$id] = $row;
        }

        return $out;
    }

    private function legacyRepo(): InMemoryLegacyExpenseFinanceBankReadRepository
    {
        return new InMemoryLegacyExpenseFinanceBankReadRepository(
            [
                $this->bankRow(1, 'alice@tricab.com', 'AU', 'AUD', '10.00', '2026-05-10', 'matched', null, 2, 9001),
                $this->bankRow(2, 'alice@tricab.com', 'AU', 'AUD', '20.00', '2026-05-11', 'unmatched', null, 2),
                $this->bankRow(3, 'alice@tricab.com', 'AU', 'AUD', '30.00', '2026-06-01', 'ambiguous', 'chased', 3),
                $this->bankRow(4, 'bob@tricab.com', 'NZ', 'NZD', '40.00', '2026-06-02', 'unmatched', 'written_off', 3, 0, 2),
            ],
            [
                $this->periodRow(2, 'AU', '2026-05-01', '2026-07-31', '2026-05-04', '2026-07-28'),
                $this->periodRow(3, 'NZ', '2026-06-01', '2026-06-30', '2026-06-02', '2026-06-29'),
            ],
        );
    }

    private function listService(
        InMemoryLegacyExpenseFinanceBankReadRepository $legacy,
        bool $enabled = true,
    ): ListExpenseFinanceBankTransactionsService {
        return new ListExpenseFinanceBankTransactionsService(
            $this->access,
            $this->bankFeeds,
            $this->periods,
            $legacy,
            new LegacyExpenseFinanceBankAssembler(),
            new LegacyExpenseFinancePeriodAssembler(),
            $enabled,
        );
    }

    private function detailService(
        InMemoryLegacyExpenseFinanceBankReadRepository $legacy,
        bool $enabled = true,
    ): GetExpenseFinanceBankTransactionService {
        return new GetExpenseFinanceBankTransactionService(
            $this->access,
            $this->bankFeeds,
            $this->receipts,
            $legacy,
            new InMemoryLegacyExpenseFinanceReadRepository([
                [
                    'id' => 9001,
                    'vendor_name' => 'Legacy Cafe',
                    'total_amount' => '10.00',
                    'currency' => 'AUD',
                    'receipt_date' => '2026-05-10',
                    'status' => 'approved',
                    'created_by' => 'alice@tricab.com',
                ],
            ]),
            new LegacyExpenseFinanceBankAssembler(),
            new LegacyExpenseFinancePeriodAssembler(),
            $enabled,
        );
    }

    private function periodsService(
        InMemoryLegacyExpenseFinanceBankReadRepository $legacy,
        bool $enabled = true,
    ): ListExpensePeriodsService {
        return new ListExpensePeriodsService(
            $this->access,
            $this->periods,
            $legacy,
            new LegacyExpenseFinancePeriodAssembler(),
            $enabled,
        );
    }

    /** @return array<string, mixed> */
    private function bankRow(
        int $id,
        string $email,
        string $country,
        string $currency,
        string $amount,
        string $transactionDate,
        string $matchStatus,
        ?string $resolution,
        int $periodId,
        int $receiptId = 0,
        int $offsetTxId = 0,
    ): array {
        return [
            'id' => $id,
            'bank_reference' => 'REF-' . $id,
            'cardholder_email' => $email,
            'country' => $country,
            'first_name' => 'First' . $id,
            'last_name' => 'Last' . $id,
            'transaction_date' => $transactionDate,
            'posting_date' => $transactionDate,
            'statement_date' => $transactionDate,
            'merchant_name' => 'Merchant ' . $id,
            'merchant_city' => 'Melbourne',
            'merchant_country' => $country,
            'merchant_postcode' => '3000',
            'merchant_category_group' => 'Travel',
            'amount' => $amount,
            'currency' => $currency,
            'foreign_amount' => null,
            'foreign_currency' => null,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'transaction_type' => 'purchase',
            'match_status' => $matchStatus,
            'receipt_id' => $receiptId,
            'uploaded_at' => $transactionDate . ' 09:00:00',
            'uploaded_by' => 'importer',
            'period_id' => $periodId,
            'resolution' => $resolution,
            'resolution_note' => null,
            'offset_tx_id' => $offsetTxId,
            'resolved_by' => $resolution !== null ? 'finance' : null,
            'resolved_at' => $resolution !== null ? $transactionDate . ' 10:00:00' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function periodRow(
        int $id,
        string $country,
        string $periodStart,
        string $periodEnd,
        string $txStart,
        string $txEnd,
    ): array {
        return [
            'id' => $id,
            'country' => $country,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'tx_start' => $txStart,
            'tx_end' => $txEnd,
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
            'created_at' => $periodStart . ' 00:00:00',
        ];
    }
}
