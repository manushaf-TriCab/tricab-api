<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\ExpensePermissionMode;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ExpensePermissionModeAccessTest extends TestCase
{
    private InMemoryExpenseProfileRepository $profiles;
    private UserId $ownerId;
    private UserId $managerId;
    private UserId $financeId;
    private UserId $adminId;

    protected function setUp(): void
    {
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->ownerId = UserId::fromInt(101);
        $this->managerId = UserId::fromInt(202);
        $this->financeId = UserId::fromInt(303);
        $this->adminId = UserId::fromInt(404);

        $this->profiles->save(new ExpenseProfile(
            $this->ownerId, 'owner', 'owner@tricab.com', true, false, false, 'mgr', $this->managerId,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->managerId, 'mgr', 'mgr@tricab.com', true, false, false, 'boss', null,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->financeId, 'fin', 'fin@tricab.com', true, true, false, 'boss', null,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->adminId, 'teadmin', 'teadmin@tricab.com', true, false, true, 'boss', null,
        ));
    }

    private function access(ExpensePermissionMode $mode): ExpenseAccessService
    {
        return new ExpenseAccessService(
            $this->profiles,
            new ProfileFinanceCapabilityResolver(),
            $mode,
        );
    }

    /**
     * @param list<string> $perms
     * @param list<string> $roles
     */
    private function actor(UserId $id, array $perms, array $roles = ['employee']): ExpenseActor
    {
        return new ExpenseActor(
            $id,
            'pub-' . $id->toInt(),
            'u' . $id->toInt() . '@tricab.com',
            $perms,
            $roles,
        );
    }

    private function pendingReceipt(UserId $owner, UserId $manager): ExpenseReceipt
    {
        $now = new DateTimeImmutable('2026-09-14T00:00:00Z', new DateTimeZone('UTC'));
        $receipt = ExpenseReceipt::createDraft(
            publicId: ExpenseReceiptPublicId::fromString('11111111-1111-4111-8111-111111111111'),
            ownerUserId: $owner,
            vendorName: 'Cafe',
            totalAmount: ExpenseMoney::fromString('11.00'),
            gstAmount: ExpenseMoney::fromString('1.00'),
            currency: ExpenseCurrency::fromString('AUD'),
            gstType: Module::GST_INCLUSIVE,
            receiptDate: $now,
            category: ExpenseCategory::fromLegacy('64100'),
            costCentre: 'MEL',
            description: null,
            attachment: null,
            now: $now,
            managerUserId: $manager,
        );
        $receipt->submit($owner, $manager, $now);

        return $receipt;
    }

    public function test_legacy_finance_comes_from_profile_only(): void
    {
        $access = $this->access(ExpensePermissionMode::legacy());
        $financeProfile = $this->profiles->findByUserId($this->financeId);
        self::assertNotNull($financeProfile);

        $withAccessOnly = $this->actor($this->financeId, [Module::PERMISSION_ACCESS]);
        self::assertTrue($access->hasFinanceCapability($withAccessOnly, $financeProfile));

        $nonFinanceProfile = $this->profiles->findByUserId($this->ownerId);
        self::assertNotNull($nonFinanceProfile);
        $withFinancePerm = $this->actor($this->ownerId, [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_FINANCE,
        ]);
        self::assertFalse($access->hasFinanceCapability($withFinancePerm, $nonFinanceProfile));
    }

    public function test_permissions_finance_from_permission_only_ignores_profile_flag(): void
    {
        $access = $this->access(ExpensePermissionMode::permissions());
        $financeProfile = $this->profiles->findByUserId($this->financeId);
        self::assertNotNull($financeProfile);

        $accessOnly = $this->actor($this->financeId, [Module::PERMISSION_ACCESS]);
        self::assertFalse($access->hasFinanceCapability($accessOnly, $financeProfile));

        $withFinancePerm = $this->actor($this->financeId, [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_FINANCE,
        ]);
        self::assertTrue($access->hasFinanceCapability($withFinancePerm, $financeProfile));
    }

    public function test_hybrid_or_and_access_only_cannot_finance(): void
    {
        $access = $this->access(ExpensePermissionMode::hybrid());

        $financeProfile = $this->profiles->findByUserId($this->financeId);
        self::assertNotNull($financeProfile);
        $legacyFinanceActor = $this->actor($this->financeId, [Module::PERMISSION_ACCESS]);
        self::assertTrue($access->hasFinanceCapability($legacyFinanceActor, $financeProfile));

        $ownerProfile = $this->profiles->findByUserId($this->ownerId);
        self::assertNotNull($ownerProfile);
        $permissionFinanceActor = $this->actor($this->ownerId, [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_FINANCE,
        ]);
        self::assertTrue($access->hasFinanceCapability($permissionFinanceActor, $ownerProfile));

        $accessOnlyOwner = $this->actor($this->ownerId, [Module::PERMISSION_ACCESS]);
        self::assertFalse($access->hasFinanceCapability($accessOnlyOwner, $ownerProfile));
    }

    public function test_permissions_assigned_manager_requires_approve(): void
    {
        $access = $this->access(ExpensePermissionMode::permissions());
        $managerProfile = $this->profiles->findByUserId($this->managerId);
        self::assertNotNull($managerProfile);
        $receipt = $this->pendingReceipt($this->ownerId, $this->managerId);

        $withoutApprove = $this->actor($this->managerId, [Module::PERMISSION_ACCESS]);
        $this->expectException(ExpenseAccessDenied::class);
        $access->assertCanActAsManager($withoutApprove, $managerProfile, $receipt);
    }

    public function test_permissions_assigned_manager_with_approve_allowed(): void
    {
        $access = $this->access(ExpensePermissionMode::permissions());
        $managerProfile = $this->profiles->findByUserId($this->managerId);
        self::assertNotNull($managerProfile);
        $receipt = $this->pendingReceipt($this->ownerId, $this->managerId);

        $withApprove = $this->actor($this->managerId, [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_APPROVE,
        ]);
        $access->assertCanActAsManager($withApprove, $managerProfile, $receipt);
        self::assertTrue(true);
    }

    public function test_permissions_admin_does_not_grant_approval_override(): void
    {
        $access = $this->access(ExpensePermissionMode::permissions());
        $adminProfile = $this->profiles->findByUserId($this->adminId);
        self::assertNotNull($adminProfile);
        $receipt = $this->pendingReceipt($this->ownerId, $this->managerId);

        $adminActor = $this->actor($this->adminId, [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_ADMIN,
        ]);
        $this->expectException(ExpenseAccessDenied::class);
        $access->assertCanActAsManager($adminActor, $adminProfile, $receipt);
    }

    public function test_permissions_finance_can_self_approve(): void
    {
        $access = $this->access(ExpensePermissionMode::permissions());
        // Owner receipt assigned to self as manager; finance override allows self-approval.
        $this->profiles->save(new ExpenseProfile(
            $this->financeId, 'fin', 'fin@tricab.com', true, false, false, 'boss', null,
        ));
        $financeProfile = $this->profiles->findByUserId($this->financeId);
        self::assertNotNull($financeProfile);
        $receipt = $this->pendingReceipt($this->financeId, $this->financeId);

        $financeActor = $this->actor($this->financeId, [
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_FINANCE,
        ]);
        $access->assertCanActAsManager($financeActor, $financeProfile, $receipt);
        self::assertTrue($access->hasFinanceCapability($financeActor, $financeProfile));
    }

    public function test_platform_administrator_without_triexpense_finance_has_no_finance(): void
    {
        $platformAdminId = UserId::fromInt(999);
        $this->profiles->save(new ExpenseProfile(
            $platformAdminId, 'padmin', 'padmin@tricab.com', true, false, false, 'boss', null,
        ));
        $profile = $this->profiles->findByUserId($platformAdminId);
        self::assertNotNull($profile);

        foreach ([
            ExpensePermissionMode::legacy(),
            ExpensePermissionMode::hybrid(),
            ExpensePermissionMode::permissions(),
        ] as $mode) {
            $access = $this->access($mode);
            $actor = $this->actor(
                $platformAdminId,
                [Module::PERMISSION_ACCESS],
                ['ADMINISTRATOR'],
            );
            self::assertFalse(
                $access->hasFinanceCapability($actor, $profile),
                'mode=' . $mode->value(),
            );
        }
    }
}
