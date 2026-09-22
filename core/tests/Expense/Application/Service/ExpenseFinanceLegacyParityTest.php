<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Legacy\InMemoryLegacyExpenseFinanceBankReadRepository;
use TriCab\Api\Expense\Legacy\InMemoryLegacyExpenseFinanceReadRepository;
use TriCab\Api\Expense\Legacy\NullLegacyExpenseFinanceReadRepository;
use TriCab\Api\Expense\Legacy\NullLegacyExpenseReadRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore;
use TriCab\Api\Expense\Persistence\InMemoryExpenseBankFeedRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpensePeriodRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExpenseReceiptAssembler;
use TriCab\Core\Expense\Application\Service\GetExpenseAttachmentFileService;
use TriCab\Core\Expense\Application\Service\GetExpenseFinanceEmployeeService;
use TriCab\Core\Expense\Application\Service\GetExpenseFinanceWorkspaceService;
use TriCab\Core\Expense\Application\Service\GetExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\GetExpenseReconciliationService;
use TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceBankAssembler;
use TriCab\Core\Expense\Application\Service\LegacyExpenseFinancePeriodAssembler;
use TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceReceiptAssembler;
use TriCab\Core\Expense\Application\Service\LegacyExpenseIdentityResolver;
use TriCab\Core\Expense\Application\Service\LegacyExpenseReceiptAssembler;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseIdentity;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

final class ExpenseFinanceLegacyParityTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseBankFeedRepository $bankFeeds;
    private InMemoryExpensePeriodRepository $periods;
    private ExpenseAccessService $access;
    private FixedClock $clock;
    private UserId $alice;
    private UserId $bob;
    private UserId $finance;
    private ExpenseActor $accessActor;
    private ExpenseActor $financeActor;

    protected function setUp(): void
    {
        $this->clock = FixedClock::utc('2026-09-14T00:00:00+00:00');
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->bankFeeds = new InMemoryExpenseBankFeedRepository();
        $this->periods = new InMemoryExpensePeriodRepository();
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());

        $this->alice = UserId::fromInt(101);
        $this->bob = UserId::fromInt(202);
        $this->finance = UserId::fromInt(303);

        $this->profiles->save(new ExpenseProfile($this->alice, 'alice', 'alice@tricab.com', true, false, false, 'mgr', null));
        $this->profiles->save(new ExpenseProfile($this->bob, 'bob', 'bob@tricab.com', true, false, false, 'mgr', null));
        $this->profiles->save(new ExpenseProfile($this->finance, 'finance', 'finance@tricab.com', true, true, false, null, null));

        $this->accessActor = new ExpenseActor($this->alice, 'alice-pub', 'alice@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $this->financeActor = new ExpenseActor(
            $this->finance,
            'fin-pub',
            'finance@tricab.com',
            [Module::PERMISSION_ACCESS, Module::PERMISSION_FINANCE],
            ['employee'],
        );
    }

    public function test_accessCannotUseFinanceWorkspace(): void
    {
        $this->expectException(ExpenseAccessDenied::class);
        $this->workspace(new InMemoryLegacyExpenseFinanceReadRepository([]), true)
            ->execute($this->accessActor);
    }

    public function test_financeSeesOrgWideLegacyReceiptsInWorkspace(): void
    {
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe', 'AUD', '12.00'),
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe', 'USD', '20.00'),
        ]);
        $result = $this->workspace($legacy, true)->execute($this->financeActor);
        self::assertSame(2, $result['summary']['legacyReceiptTotal']);
        self::assertGreaterThanOrEqual(2, count($result['employees']));
        $currencies = array_column($result['currencyTotals'], 'currency');
        self::assertContains('AUD', $currencies);
        self::assertContains('USD', $currencies);
    }

    public function test_financeEmployeeMergesLegacyAndV2(): void
    {
        $this->saveV2($this->alice, 'Azure Cafe', 'AUD', '5.00');
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe', 'AUD', '12.00', 'approved', null, 'https://example.com/r.pdf', 'r.pdf'),
        ]);
        $result = $this->employee($legacy, true)->execute($this->financeActor, 101);
        self::assertCount(2, $result['receipts']);
        $sources = array_column($result['receipts'], 'source');
        self::assertContains('v2', $sources);
        self::assertContains('legacy', $sources);
        self::assertNotEmpty($result['currencyTotals']);
    }

    public function test_migratedLegacyDeduped(): void
    {
        $this->saveV2($this->alice, 'Migrated', 'AUD', '12.00', LegacyExpenseIdentity::of(createdBy: 'alice@tricab.com', legacyReceiptId: 11));
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe', 'AUD', '12.00'),
        ]);
        $result = $this->employee($legacy, true)->execute($this->financeActor, 101);
        $legacyItems = array_values(array_filter($result['receipts'], static fn ($r) => ($r['source'] ?? '') === 'legacy'));
        self::assertCount(0, $legacyItems);
        self::assertCount(1, $result['receipts']);
    }

    public function test_accessCannotReadOtherLegacyDetail(): void
    {
        $legacyOwner = new class implements \TriCab\Core\Expense\Contract\LegacyExpenseReadRepository {
            public function listForOwnerKeys(array $ownerKeys, int $limit = 500): array { return []; }
            public function findOwnedById(int $legacyReceiptId, array $ownerKeys): ?array { return null; }
        };
        $financeRepo = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe', 'USD', '20.00'),
        ]);
        $svc = new GetExpenseReceiptService(
            $this->access,
            $this->profiles,
            $this->receipts,
            new ExpenseReceiptAssembler(),
            $legacyOwner,
            new LegacyExpenseReceiptAssembler(),
            new LegacyExpenseIdentityResolver(),
            $financeRepo,
            true,
        );
        $this->expectException(ExpenseReceiptNotFound::class);
        $svc->execute($this->accessActor, 'legacy:12');
    }

    public function test_financeCanReadOtherLegacyDetailAndAttachment(): void
    {
        $financeRepo = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe', 'USD', '20.00', 'approved', null, 'https://example.com/bob.pdf', 'bob.pdf'),
        ]);
        $get = new GetExpenseReceiptService(
            $this->access,
            $this->profiles,
            $this->receipts,
            new ExpenseReceiptAssembler(),
            new NullLegacyExpenseReadRepository(),
            new LegacyExpenseReceiptAssembler(),
            new LegacyExpenseIdentityResolver(),
            $financeRepo,
            true,
        );
        $details = $get->execute($this->financeActor, 'legacy:12');
        self::assertSame('legacy', $details->source);
        self::assertSame('Bob Cafe', $details->vendorName);

        $attach = new GetExpenseAttachmentFileService(
            $this->access,
            $this->profiles,
            $this->receipts,
            new InMemoryExpenseAttachmentStore(),
            new NullLegacyExpenseReadRepository(),
            new LegacyExpenseIdentityResolver(),
            $financeRepo,
            true,
        );
        $ticket = $attach->execute($this->financeActor, 'legacy:12');
        self::assertSame('https://example.com/bob.pdf', $ticket['url']);
        self::assertSame('legacy', $ticket['source']);
    }

    public function test_accessCannotFetchOtherLegacyAttachment(): void
    {
        $financeRepo = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe', 'USD', '20.00', 'approved', null, 'https://example.com/bob.pdf', 'bob.pdf'),
        ]);
        $attach = new GetExpenseAttachmentFileService(
            $this->access,
            $this->profiles,
            $this->receipts,
            new InMemoryExpenseAttachmentStore(),
            new NullLegacyExpenseReadRepository(),
            new LegacyExpenseIdentityResolver(),
            $financeRepo,
            true,
        );
        $this->expectException(ExpenseReceiptNotFound::class);
        $attach->execute($this->accessActor, 'legacy:12');
    }

    public function test_legacyResolveStillBlocked(): void
    {
        $this->expectException(ExpenseLegacyReadOnly::class);
        LegacyExpenseReceiptRef::assertNotLegacy('legacy:12');
    }

    public function test_reconciliationIncludesLegacyUnmatchedAndCurrencyBuckets(): void
    {
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe', 'AUD', '12.00'),
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe', 'USD', '20.00'),
        ]);
        $svc = new GetExpenseReconciliationService(
            $this->access,
            $this->bankFeeds,
            $this->receipts,
            $this->periods,
            $this->profiles,
            $legacy,
            new LegacyExpenseFinanceReceiptAssembler(),
            new InMemoryLegacyExpenseFinanceBankReadRepository(),
            new LegacyExpenseFinanceBankAssembler(),
            new LegacyExpenseFinancePeriodAssembler(),
            true,
        );
        $result = $svc->execute($this->financeActor);
        self::assertSame(2, $result['counts']['unmatchedReceipts']);
        self::assertCount(2, $result['currencyTotals']);
    }

    public function test_financeFiltersAndPagination(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->legacyRow(100 + $i, 'alice@tricab.com', sprintf('2026-01-%02d', $i), 'Cafe ' . $i, 'AUD', '10.00');
        }
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository($rows);
        $page1 = $this->workspace($legacy, true)->execute(
            $this->financeActor,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            1,
            2,
        );
        self::assertSame(1, $page1['pagination']['page']);
        self::assertSame(2, $page1['pagination']['pageSize']);
        self::assertLessThanOrEqual(2, count($page1['employees']));
    }

    public function test_legacySqlGuardStillBlocksWrites(): void
    {
        $guard = new LegacyReadOnlySqlGuard();
        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $guard->assertReadOnly('UPDATE tc.triexpense_receipts SET status=1');
    }

    public function test_customerAndManagerMappedOnLegacyView(): void
    {
        $row = $this->legacyRow(55, 'alice@tricab.com', '2026-03-01', 'Cust Cafe', 'NZD', '33.00');
        $row['customer_code'] = 'C001';
        $row['customer_name'] = 'Acme';
        $row['manager_login'] = 'mgr';
        $row['manager_display_name'] = 'Manager Person';
        $view = (new LegacyExpenseFinanceReceiptAssembler())->toFinanceView($row);
        self::assertSame('C001', $view['customer']['customerCode']);
        self::assertSame('Manager Person', $view['managerDisplayName']);
        self::assertFalse($view['mutable']);
        self::assertFalse($view['actions']['resolve']);
    }

    public function test_workspaceIncludesUnmappedLegacyEmployeeWithoutCrashing(): void
    {
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(90, 'ghost@tricab.com', '2026-01-03', 'Ghost Cafe', 'AUD', '9.00'),
            $this->legacyRow(91, '', '2026-01-04', 'No Owner Cafe', 'AUD', '8.00'),
        ]);
        $bank = new InMemoryLegacyExpenseFinanceBankReadRepository([
            $this->legacyBankRow(1, 'ghost@tricab.com', 'unmatched', null, null),
            $this->legacyBankRow(2, '', 'unmatched', null, null),
            $this->legacyBankRow(3, 'alice@tricab.com', 'matched', 'written_off', null),
            $this->legacyBankRow(4, 'alice@tricab.com', 'ambiguous', null, null),
            $this->legacyBankRow(5, 'alice@tricab.com', 'unmatched', 'offset', null),
            $this->legacyBankRow(6, 'alice@tricab.com', 'unmatched', null, null),
        ]);
        $result = $this->workspace($legacy, true, $bank)->execute($this->financeActor);
        $emails = array_map(
            static fn (array $row): ?string => isset($row['email']) && is_string($row['email']) ? $row['email'] : null,
            $result['employees'],
        );
        self::assertContains('ghost@tricab.com', $emails);
        $ghost = null;
        foreach ($result['employees'] as $employee) {
            if (($employee['email'] ?? null) === 'ghost@tricab.com') {
                $ghost = $employee;
                break;
            }
        }
        self::assertNotNull($ghost);
        self::assertNull($ghost['userId']);
        self::assertSame('ghost', $ghost['displayName'] ?? null);
        self::assertGreaterThanOrEqual(1, $ghost['stats']['needsReceipt']);
        self::assertSame(6, $result['summary']['legacyBankTotal']);

        $unmapped = null;
        foreach ($result['employees'] as $employee) {
            if (($employee['key'] ?? '') === 'unmapped:cardholder' || ($employee['displayName'] ?? '') === 'Unmapped cardholder') {
                $unmapped = $employee;
                break;
            }
        }
        self::assertNotNull($unmapped);
        self::assertGreaterThanOrEqual(1, $unmapped['bankCount']);
    }

    public function test_workspaceHandlesNullPeriodAndMixedBankStatuses(): void
    {
        $bank = new InMemoryLegacyExpenseFinanceBankReadRepository([
            $this->legacyBankRow(10, 'alice@tricab.com', 'matched', null, 1),
            $this->legacyBankRow(11, 'alice@tricab.com', 'unmatched', null, null),
            $this->legacyBankRow(12, 'alice@tricab.com', 'ambiguous', 'chased', null),
            $this->legacyBankRow(13, 'alice@tricab.com', 'unmatched', 'written_off', null),
            $this->legacyBankRow(14, 'alice@tricab.com', 'unmatched', 'offset', 2),
        ]);
        $result = $this->workspace(new InMemoryLegacyExpenseFinanceReadRepository([]), true, $bank)->execute($this->financeActor);
        $alice = null;
        foreach ($result['employees'] as $employee) {
            if (($employee['email'] ?? null) === 'alice@tricab.com') {
                $alice = $employee;
                break;
            }
        }
        self::assertNotNull($alice);
        self::assertSame(2, $alice['stats']['needsReceipt']);
        self::assertSame(5, $alice['bankCount']);
    }

    public function test_workspaceMergesV2AndLegacyWithoutCurrencyFilterDefault(): void
    {
        $this->saveV2($this->alice, 'Azure Cafe', 'NZD', '5.00');
        $legacy = new InMemoryLegacyExpenseFinanceReadRepository([
            $this->legacyRow(21, 'alice@tricab.com', '2026-01-01', 'Alice Cafe', 'AUD', '12.00'),
        ]);
        $result = $this->workspace($legacy, true)->execute($this->financeActor);
        self::assertNull($result['filters']['currency']);
        $currencies = array_column($result['currencyTotals'], 'currency');
        self::assertContains('AUD', $currencies);
        self::assertContains('NZD', $currencies);
    }

    private function workspace(
        InMemoryLegacyExpenseFinanceReadRepository|NullLegacyExpenseFinanceReadRepository $legacy,
        bool $enabled,
        ?InMemoryLegacyExpenseFinanceBankReadRepository $bank = null,
    ): GetExpenseFinanceWorkspaceService
    {
        $adminQuery = new class ($this->profiles) implements ExpenseProfileAdminQuery {
            public function __construct(private InMemoryExpenseProfileRepository $profiles)
            {
            }

            public function listActiveProfiles(): array
            {
                $out = [];
                foreach ([101, 202, 303] as $id) {
                    $profile = $this->profiles->findByUserId(UserId::fromInt($id));
                    if ($profile === null || !$profile->isActive()) {
                        continue;
                    }
                    $out[] = [
                        'userId' => $id,
                        'publicId' => 'user-' . $id,
                        'displayName' => $profile->legacyEmail(),
                        'email' => $profile->legacyEmail(),
                        'legacyLogin' => $profile->legacyLogin(),
                    ];
                }

                return $out;
            }
        };

        return new GetExpenseFinanceWorkspaceService(
            $this->access,
            $this->bankFeeds,
            $this->receipts,
            $this->periods,
            $adminQuery,
            $legacy,
            new LegacyExpenseFinanceReceiptAssembler(),
            $bank ?? new InMemoryLegacyExpenseFinanceBankReadRepository(),
            new LegacyExpenseFinanceBankAssembler(),
            new LegacyExpenseFinancePeriodAssembler(),
            $enabled,
        );
    }

    private function employee(InMemoryLegacyExpenseFinanceReadRepository $legacy, bool $enabled): GetExpenseFinanceEmployeeService
    {
        return new GetExpenseFinanceEmployeeService(
            $this->access,
            $this->profiles,
            $this->receipts,
            $this->bankFeeds,
            $legacy,
            new LegacyExpenseFinanceReceiptAssembler(),
            new LegacyExpenseIdentityResolver(),
            new InMemoryLegacyExpenseFinanceBankReadRepository(),
            new LegacyExpenseFinanceBankAssembler(),
            $enabled,
        );
    }

    private function saveV2(UserId $owner, string $vendor, string $currency, string $amount, ?LegacyExpenseIdentity $legacy = null): void
    {
        $receipt = ExpenseReceipt::createDraft(
            publicId: ExpenseReceiptPublicId::generate(),
            ownerUserId: $owner,
            vendorName: $vendor,
            totalAmount: ExpenseMoney::fromString($amount),
            gstAmount: ExpenseMoney::fromString('0.00'),
            currency: ExpenseCurrency::fromString($currency),
            gstType: 'no_gst',
            receiptDate: new DateTimeImmutable('2026-02-01'),
            category: ExpenseCategory::fromLegacy('10000', null),
            costCentre: 'MEL',
            description: null,
            attachment: null,
            now: $this->clock->now(),
            managerUserId: null,
            legacy: $legacy ?? LegacyExpenseIdentity::of(createdBy: 'alice@tricab.com'),
        );
        $receipt->submit($owner, UserId::fromInt(99), $this->clock->now());
        $receipt->approve($this->clock->now());
        $this->receipts->save($receipt);
    }

    /** @return array<string, mixed> */
    private function legacyBankRow(
        int $id,
        string $email,
        string $matchStatus,
        ?string $resolution,
        ?int $periodId,
    ): array {
        return [
            'id' => $id,
            'bank_reference' => 'ref-' . $id,
            'cardholder_email' => $email,
            'first_name' => $email !== '' ? explode('@', $email)[0] : '',
            'last_name' => '',
            'country' => 'AU',
            'transaction_date' => '2026-01-05',
            'posting_date' => '2026-01-06',
            'statement_date' => '2026-01-07',
            'merchant_name' => 'Merchant ' . $id,
            'merchant_city' => null,
            'merchant_country' => null,
            'merchant_postcode' => null,
            'merchant_category_group' => null,
            'amount' => '10.00',
            'currency' => 'AUD',
            'foreign_amount' => null,
            'foreign_currency' => null,
            'net_amount' => null,
            'tax_amount' => null,
            'transaction_type' => 'purchase',
            'match_status' => $matchStatus,
            'receipt_id' => $matchStatus === 'matched' ? 100 + $id : null,
            'period_id' => $periodId,
            'resolution' => $resolution,
            'resolution_note' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'offset_tx_id' => $resolution === 'offset' ? 999 : null,
            'uploaded_at' => '2026-01-05 10:00:00',
            'uploaded_by' => 'finance',
        ];
    }

    /** @return array<string, mixed> */
    private function legacyRow(
        int $id,
        string $createdBy,
        string $date,
        string $vendor,
        string $currency = 'AUD',
        string $amount = '12.00',
        string $status = 'approved',
        ?string $resolution = null,
        string $fileUrl = '',
        string $fileName = '',
    ): array {
        return [
            'id' => $id,
            'created_by' => $createdBy,
            'vendor_name' => $vendor,
            'total_amount' => $amount,
            'gst_amount' => '0.00',
            'currency' => $currency,
            'gst_type' => 'no_gst',
            'receipt_date' => $date,
            'category' => '10000',
            'other_category' => null,
            'cost_centre' => 'MEL',
            'description' => null,
            'status' => $status,
            'manager_status' => $status === 'approved' ? 'approved' : 'pending',
            'manager_reason' => null,
            'manager_login' => 'mgr',
            'resolution' => $resolution,
            'resolution_note' => null,
            'bank_feed_id' => null,
            'period_id' => null,
            'statement_period' => null,
            'file_url' => $fileUrl,
            'file_name' => $fileName,
            'customer_code' => null,
            'customer_name' => null,
            'customer_state' => null,
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'submitter_display_name' => $createdBy !== '' ? explode('@', $createdBy)[0] : 'Legacy employee',
            'submitter_branch' => 'MEL',
            'submitter_department' => 'Ops',
            'manager_display_name' => 'Manager',
            'created_at' => $date . ' 10:00:00',
            'updated_at' => $date . ' 10:00:00',
            'deleted_at' => null,
        ];
    }
}
