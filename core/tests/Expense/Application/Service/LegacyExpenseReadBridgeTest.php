<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Legacy\MysqlLegacyExpenseReadRepository;
use TriCab\Api\Expense\Legacy\NullLegacyExpenseReadRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExpenseReceiptAssembler;
use TriCab\Core\Expense\Application\Service\GetExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\LegacyExpenseIdentityResolver;
use TriCab\Core\Expense\Application\Service\LegacyExpenseReceiptAssembler;
use TriCab\Core\Expense\Application\Service\ListMyExpenseReceiptsService;
use TriCab\Core\Expense\Application\Service\UpdateExpenseReceiptService;
use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;
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
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use DateTimeImmutable;

final class LegacyExpenseReadBridgeTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private ExpenseAccessService $access;
    private ExpenseReceiptAssembler $assembler;
    private LegacyExpenseReceiptAssembler $legacyAssembler;
    private LegacyExpenseIdentityResolver $identity;
    private FixedClock $clock;

    private UserId $userA;
    private UserId $userB;
    private ExpenseActor $actorA;
    private ExpenseActor $actorB;

    protected function setUp(): void
    {
        $this->clock = FixedClock::utc('2026-09-14T00:00:00+00:00');
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());
        $this->assembler = new ExpenseReceiptAssembler();
        $this->legacyAssembler = new LegacyExpenseReceiptAssembler();
        $this->identity = new LegacyExpenseIdentityResolver();

        $this->userA = UserId::fromInt(101);
        $this->userB = UserId::fromInt(202);
        $this->profiles->save(new ExpenseProfile(
            $this->userA, 'alice', 'alice@tricab.com', true, false, false, 'mgr', null,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->userB, 'bob', 'bob@tricab.com', true, false, false, 'mgr', null,
        ));
        $this->actorA = $this->actor($this->userA, 'a-pub', 'alice@tricab.com');
        $this->actorB = $this->actor($this->userB, 'b-pub', 'bob@tricab.com');
    }

    public function test_userAGetsOwnLegacyReceiptsOnly(): void
    {
        $legacy = $this->fakeLegacy([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe'),
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe'),
        ]);
        $items = $this->listService($legacy, true)->execute($this->actorA);
        $legacyItems = array_values(array_filter($items, static fn ($i) => $i->source === 'legacy'));
        self::assertCount(1, $legacyItems);
        self::assertSame('legacy:11', $legacyItems[0]->publicId);
        self::assertSame('Alice Cafe', $legacyItems[0]->vendorName);
    }

    public function test_userBGetsOwnLegacyReceiptsOnly(): void
    {
        $legacy = $this->fakeLegacy([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe'),
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe'),
        ]);
        $items = $this->listService($legacy, true)->execute($this->actorB);
        $legacyItems = array_values(array_filter($items, static fn ($i) => $i->source === 'legacy'));
        self::assertCount(1, $legacyItems);
        self::assertSame('legacy:12', $legacyItems[0]->publicId);
    }

    public function test_userACannotReadUserBLegacyDetail(): void
    {
        $legacy = $this->fakeLegacy([
            $this->legacyRow(12, 'bob@tricab.com', '2026-01-02', 'Bob Cafe'),
        ]);
        $this->expectException(ExpenseReceiptNotFound::class);
        $this->getService($legacy, true)->execute($this->actorA, 'legacy:12');
    }

    public function test_userWithNoLegacyReceiptsStillWorks(): void
    {
        $this->saveNewDraft($this->userA, 'New Only', 'alice@tricab.com');
        $items = $this->listService($this->fakeLegacy([]), true)->execute($this->actorA);
        self::assertCount(1, $items);
        self::assertSame('new', $items[0]->source);
    }

    public function test_userWithNoExpenseProfileStillListsNewReceipts(): void
    {
        $orphanId = UserId::fromInt(777);
        $orphan = $this->actor($orphanId, 'orphan-pub', 'orphan@tricab.com');
        $this->saveNewDraft($orphanId, 'Orphan New', 'orphan@tricab.com');
        $legacy = $this->fakeLegacy([
            $this->legacyRow(99, 'orphan@tricab.com', '2026-01-01', 'Should Not Appear'),
        ]);
        $items = $this->listService($legacy, true)->execute($orphan);
        self::assertCount(1, $items);
        self::assertSame('new', $items[0]->source);
    }

    public function test_legacyReadDisabledReturnsOnlyNew(): void
    {
        $legacy = $this->fakeLegacy([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe'),
        ]);
        $items = $this->listService($legacy, false)->execute($this->actorA);
        self::assertSame([], array_values(array_filter($items, static fn ($i) => $i->source === 'legacy')));
    }

    public function test_legacyDetailWorksForOwner(): void
    {
        $legacy = $this->fakeLegacy([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe'),
        ]);
        $details = $this->getService($legacy, true)->execute($this->actorA, 'legacy:11');
        self::assertSame('legacy', $details->source);
        self::assertSame('Alice Cafe', $details->vendorName);
    }

    public function test_legacyMutationRejected(): void
    {
        $this->expectException(ExpenseLegacyReadOnly::class);
        LegacyExpenseReceiptRef::assertNotLegacy('legacy:11');
    }

    public function test_updateServiceRejectsLegacyId(): void
    {
        $svc = new UpdateExpenseReceiptService(
            $this->access,
            $this->receipts,
            new InMemoryExpenseAuditRecorder(),
            $this->assembler,
            $this->clock,
        );
        $this->expectException(ExpenseLegacyReadOnly::class);
        $svc->execute($this->actorA, 'legacy:11', ['vendorName' => 'Nope']);
    }

    public function test_migratedLegacyDuplicateSuppressed(): void
    {
        $this->saveNewDraft(
            $this->userA,
            'Migrated Azure',
            'alice@tricab.com',
            LegacyExpenseIdentity::of(createdBy: 'alice@tricab.com', legacyReceiptId: 11),
        );
        $legacy = $this->fakeLegacy([
            $this->legacyRow(11, 'alice@tricab.com', '2026-01-01', 'Alice Cafe'),
        ]);
        $items = $this->listService($legacy, true)->execute($this->actorA);
        $legacyItems = array_values(array_filter($items, static fn ($i) => $i->source === 'legacy'));
        $newItems = array_values(array_filter($items, static fn ($i) => $i->source === 'new'));
        self::assertCount(0, $legacyItems);
        self::assertCount(1, $newItems);
        self::assertSame('Migrated Azure', $newItems[0]->vendorName);
    }

    public function test_noHardcodedThilanEmailInBridge(): void
    {
        $files = [
            dirname(__DIR__, 4) . '/src/Expense/Application/Service/ListMyExpenseReceiptsService.php',
            dirname(__DIR__, 4) . '/src/Expense/Application/Service/GetExpenseReceiptService.php',
            dirname(__DIR__, 4) . '/src/Expense/Application/Service/LegacyExpenseIdentityResolver.php',
            dirname(__DIR__, 5) . '/src/Expense/Legacy/MysqlLegacyExpenseReadRepository.php',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file, $file);
            $contents = strtolower((string) file_get_contents($file));
            self::assertStringNotContainsString('thilanh@tricab.com', $contents);
        }
    }

    public function test_legacySqlGuardRejectsWrites(): void
    {
        $guard = new LegacyReadOnlySqlGuard();
        new MysqlLegacyExpenseReadRepository(
            new class implements \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection {
                public function sourceLabel(): string { return 'test'; }
                public function query(string $sql, array $params = [], string $repository = 'unknown', string $method = 'unknown'): array { return []; }
            },
            $guard,
        );
        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $guard->assertReadOnly('UPDATE tc.triexpense_receipts SET status=1');
    }

    public function test_accessWithoutPermissionDenied(): void
    {
        $noAccess = new ExpenseActor($this->userA, 'a-pub', 'alice@tricab.com', [], ['employee']);
        $this->expectException(ExpenseAccessDenied::class);
        $this->listService(new NullLegacyExpenseReadRepository(), true)->execute($noAccess);
    }

    private function saveNewDraft(
        UserId $owner,
        string $vendor,
        string $legacyCreatedBy,
        ?LegacyExpenseIdentity $legacy = null,
    ): void {
        $receipt = ExpenseReceipt::createDraft(
            publicId: ExpenseReceiptPublicId::generate(),
            ownerUserId: $owner,
            vendorName: $vendor,
            totalAmount: ExpenseMoney::fromString('10.00'),
            gstAmount: ExpenseMoney::fromString('0.00'),
            currency: ExpenseCurrency::fromString('AUD'),
            gstType: 'no_gst',
            receiptDate: new DateTimeImmutable('2026-02-01'),
            category: ExpenseCategory::fromLegacy('10000', null),
            costCentre: 'MEL',
            description: null,
            attachment: null,
            now: $this->clock->now(),
            managerUserId: null,
            legacy: $legacy ?? LegacyExpenseIdentity::of(createdBy: $legacyCreatedBy),
        );
        $this->receipts->save($receipt);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fakeLegacy(array $rows): LegacyExpenseReadRepository
    {
        return new class ($rows) implements LegacyExpenseReadRepository {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function listForOwnerKeys(array $ownerKeys, int $limit = 500): array
            {
                $keys = array_fill_keys(array_map('strtolower', $ownerKeys), true);
                $out = [];
                foreach ($this->rows as $row) {
                    $owner = strtolower(trim((string) ($row['created_by'] ?? '')));
                    if (isset($keys[$owner])) {
                        $out[] = $row;
                    }
                }

                return $out;
            }

            public function findOwnedById(int $legacyReceiptId, array $ownerKeys): ?array
            {
                foreach ($this->listForOwnerKeys($ownerKeys) as $row) {
                    if ((int) $row['id'] === $legacyReceiptId) {
                        return $row;
                    }
                }

                return null;
            }
        };
    }

    /** @return array<string, mixed> */
    private function legacyRow(int $id, string $createdBy, string $date, string $vendor): array
    {
        return [
            'id' => $id,
            'created_by' => $createdBy,
            'vendor_name' => $vendor,
            'total_amount' => '12.00',
            'gst_amount' => '0.00',
            'currency' => 'AUD',
            'gst_type' => 'no_gst',
            'receipt_date' => $date,
            'category' => '10000',
            'other_category' => null,
            'cost_centre' => 'MEL',
            'description' => null,
            'status' => 'approved',
            'manager_status' => 'approved',
            'manager_reason' => null,
            'file_url' => '',
            'file_name' => '',
            'customer_code' => null,
            'customer_name' => null,
            'customer_state' => null,
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'created_at' => $date . ' 10:00:00',
            'updated_at' => $date . ' 10:00:00',
            'deleted_at' => null,
        ];
    }

    private function listService(LegacyExpenseReadRepository $legacy, bool $enabled): ListMyExpenseReceiptsService
    {
        return new ListMyExpenseReceiptsService(
            $this->access,
            $this->profiles,
            $this->receipts,
            $this->assembler,
            $legacy,
            $this->legacyAssembler,
            $this->identity,
            $enabled,
        );
    }

    private function getService(LegacyExpenseReadRepository $legacy, bool $enabled): GetExpenseReceiptService
    {
        return new GetExpenseReceiptService(
            $this->access,
            $this->profiles,
            $this->receipts,
            $this->assembler,
            $legacy,
            $this->legacyAssembler,
            $this->identity,
            new \TriCab\Api\Expense\Legacy\NullLegacyExpenseFinanceReadRepository(),
            $enabled,
        );
    }

    private function actor(UserId $id, string $publicId, string $email): ExpenseActor
    {
        return new ExpenseActor($id, $publicId, $email, [Module::PERMISSION_ACCESS], ['employee']);
    }
}
