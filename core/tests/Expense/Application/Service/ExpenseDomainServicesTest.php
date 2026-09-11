<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository;
use TriCab\Api\Expense\Persistence\NullExpenseNotificationPort;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Api\Expense\Persistence\ProfileManagerResolver;
use TriCab\Api\Expense\Legacy\ReadOnlyLegacyExpenseReceiptSource;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\Exception\ExpenseManagerRequired;
use TriCab\Core\Expense\Application\Service\CheckExpenseDuplicateService;
use TriCab\Core\Expense\Application\Service\CompleteExpenseAttachmentService;
use TriCab\Core\Expense\Application\Service\CreateExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExpenseApprovalService;
use TriCab\Core\Expense\Application\Service\ExpenseMigrationDryRunClassifier;
use TriCab\Core\Expense\Application\Service\ExpenseReceiptAssembler;
use TriCab\Core\Expense\Application\Service\GetExpenseAttachmentFileService;
use TriCab\Core\Expense\Application\Service\InitiateExpenseAttachmentService;
use TriCab\Core\Expense\Application\Service\ListExpenseApprovalsService;
use TriCab\Core\Expense\Application\Service\ListMyExpenseReceiptsService;
use TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\RestoreExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\SoftDeleteExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\SubmitExpenseReceiptService;
use TriCab\Core\Expense\Application\Service\UpdateExpenseReceiptService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseStatusTransition;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMigrationReadiness;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseMoney;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

final class ExpenseDomainServicesTest extends TestCase
{
    private InMemoryExpenseReceiptRepository $receipts;
    private InMemoryExpenseProfileRepository $profiles;
    private InMemoryExpenseAuditRecorder $audit;
    private ExpenseAccessService $access;
    private ProfileManagerResolver $managers;
    private ExpenseReceiptAssembler $assembler;
    private FixedClock $clock;
    private NullExpenseNotificationPort $notifications;

    private UserId $ownerId;
    private UserId $managerId;
    private UserId $strangerId;
    private ExpenseActor $owner;
    private ExpenseActor $manager;
    private ExpenseActor $stranger;
    private ExpenseActor $platformAdminNoTeAdmin;

    protected function setUp(): void
    {
        $this->clock = FixedClock::utc('2026-09-07T04:00:00+00:00');
        $this->receipts = new InMemoryExpenseReceiptRepository();
        $this->profiles = new InMemoryExpenseProfileRepository();
        $this->audit = new InMemoryExpenseAuditRecorder();
        $this->notifications = new NullExpenseNotificationPort();
        $this->managers = new ProfileManagerResolver($this->profiles);
        $this->access = new ExpenseAccessService($this->profiles, new ProfileFinanceCapabilityResolver());
        $this->assembler = new ExpenseReceiptAssembler();

        $this->ownerId = UserId::fromInt(101);
        $this->managerId = UserId::fromInt(202);
        $this->strangerId = UserId::fromInt(303);

        $this->profiles->save(new ExpenseProfile(
            $this->ownerId, 'owner', 'owner@tricab.com', true, false, false, 'mgr', $this->managerId,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->managerId, 'mgr', 'mgr@tricab.com', true, false, false, 'boss', null,
        ));
        $this->profiles->save(new ExpenseProfile(
            $this->strangerId, 'stranger', 'stranger@tricab.com', true, false, false, 'mgr', $this->managerId,
        ));

        $this->owner = $this->actor($this->ownerId, 'owner-pub', 'owner@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $this->manager = $this->actor($this->managerId, 'mgr-pub', 'mgr@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $this->stranger = $this->actor($this->strangerId, 'str-pub', 'stranger@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $this->platformAdminNoTeAdmin = $this->actor(
            UserId::fromInt(999),
            'admin-pub',
            'admin@tricab.com',
            [Module::PERMISSION_ACCESS],
            ['ADMINISTRATOR'],
        );
        $this->profiles->save(new ExpenseProfile(
            UserId::fromInt(999), 'admin', 'admin@tricab.com', true, false, false, 'mgr', $this->managerId,
        ));
    }

    private function actor(UserId $id, string $publicId, string $email, array $perms, array $roles): ExpenseActor
    {
        return new ExpenseActor($id, $publicId, $email, $perms, $roles);
    }

    private function createService(): CreateExpenseReceiptService
    {
        return new CreateExpenseReceiptService(
            $this->access, $this->receipts, $this->managers, $this->audit, $this->assembler, $this->clock,
        );
    }

    private function submitService(): SubmitExpenseReceiptService
    {
        return new SubmitExpenseReceiptService(
            $this->access, $this->receipts, $this->profiles, $this->managers, $this->notifications, $this->audit, $this->assembler, $this->clock,
        );
    }

    private function draft(array $payload = []): \TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails
    {
        return $this->createService()->execute($this->owner, array_merge([
            'vendorName' => 'Cafe',
            'totalAmount' => '20.00',
            'gstAmount' => '1.82',
            'currency' => 'AUD',
            'category' => '66700',
        ], $payload));
    }

    private function pending(array $payload = []): \TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails
    {
        $created = $this->draft($payload);

        return $this->submitService()->execute($this->owner, $created->publicId, (string) $created->rowVersion);
    }

    private function approvalService(): ExpenseApprovalService
    {
        return new ExpenseApprovalService(
            $this->access, $this->receipts, $this->notifications, $this->audit, $this->assembler, $this->clock,
        );
    }

    public function test_receiptOwnerDerivedFromAuthenticatedUser(): void
    {
        $created = $this->createService()->execute($this->owner, [
            'vendorName' => 'Officeworks',
            'totalAmount' => '12.50',
            'gstAmount' => '1.14',
            'currency' => 'AUD',
            'category' => '64100',
            'costCentre' => 'Port Melbourne',
        ]);

        $stored = $this->receipts->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId::fromString($created->publicId),
        );
        self::assertNotNull($stored);
        self::assertTrue($stored->ownerUserId()->equals($this->ownerId));
        self::assertSame('draft', $created->status);
    }

    public function test_normalUserCannotCreateReceiptForAnotherUser(): void
    {
        $this->expectException(ExpenseAccessDenied::class);
        $this->createService()->execute($this->owner, [
            'vendorName' => 'X',
            'totalAmount' => '1.00',
            'gstAmount' => '0.10',
            'currency' => 'AUD',
            'ownerUserId' => 999,
            'category' => '64100',
        ]);
    }

    public function test_inactiveExpenseProfileDenied(): void
    {
        $inactive = UserId::fromInt(404);
        $this->profiles->save(new ExpenseProfile(
            $inactive, 'inactive', 'inactive@tricab.com', false, false, false, 'mgr', $this->managerId,
        ));
        $actor = $this->actor($inactive, 'inact', 'inactive@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);

        $this->expectException(ExpenseAccessDenied::class);
        $this->createService()->execute($actor, [
            'vendorName' => 'X', 'totalAmount' => '1', 'gstAmount' => '0', 'currency' => 'AUD', 'category' => '64100',
        ]);
    }

    public function test_triExpenseAccessPermissionRequired(): void
    {
        $actor = $this->actor($this->ownerId, 'owner-pub', 'owner@tricab.com', [], ['employee']);
        $this->expectException(ExpenseAccessDenied::class);
        $this->createService()->execute($actor, [
            'vendorName' => 'X', 'totalAmount' => '1', 'gstAmount' => '0', 'currency' => 'AUD', 'category' => '64100',
        ]);
    }

    public function test_draftReceiptCanBeSubmitted(): void
    {
        $submitted = $this->pending();
        self::assertSame('pending', $submitted->status);
        self::assertSame('pending', $submitted->managerStatus);
    }

    public function test_pendingReceiptCanBeApprovedByAssignedManager(): void
    {
        $submitted = $this->pending();
        $approved = $this->approvalService()->approve($this->manager, $submitted->publicId, (string) $submitted->rowVersion);
        self::assertSame('approved', $approved->status);
        self::assertSame('approved', $approved->managerStatus);
    }

    public function test_nonManagerCannotApproveReceipt(): void
    {
        $submitted = $this->pending();

        $this->expectException(ExpenseAccessDenied::class);
        $this->approvalService()->approve($this->stranger, $submitted->publicId, (string) $submitted->rowVersion);
    }

    public function test_pendingReceiptCanBeRejectedByAssignedManager(): void
    {
        $submitted = $this->pending();
        $rejected = $this->approvalService()->reject($this->manager, $submitted->publicId, 'Missing GST', (string) $submitted->rowVersion);
        self::assertSame('rejected', $rejected->status);
        self::assertSame('Missing GST', $rejected->managerReason);
    }

    public function test_invalidStatusTransitionRejected(): void
    {
        $created = $this->draft();
        $this->expectException(InvalidExpenseStatusTransition::class);
        $this->approvalService()->approve($this->manager, $created->publicId, (string) $created->rowVersion);
    }

    public function test_platformAdministratorDoesNotAutomaticallyBecomeExpenseAdmin(): void
    {
        $profile = $this->profiles->findByUserId(UserId::fromInt(999));
        self::assertNotNull($profile);
        self::assertTrue($this->access->platformAdministratorIsNotExpenseAdmin($this->platformAdminNoTeAdmin, $profile));
        self::assertFalse($this->access->isExpenseAdmin($profile));
    }

    public function test_financeCapabilityNotGrantedFromAccessPermissionAlone(): void
    {
        $profile = $this->profiles->findByUserId($this->ownerId);
        self::assertNotNull($profile);
        self::assertFalse($this->access->hasFinanceCapability($this->owner, $profile));

        $financeUser = UserId::fromInt(505);
        $this->profiles->save(new ExpenseProfile(
            $financeUser, 'fin', 'fin@tricab.com', true, true, false, 'mgr', $this->managerId,
        ));
        $finActor = $this->actor($financeUser, 'fin-pub', 'fin@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $finProfile = $this->profiles->findByUserId($financeUser);
        self::assertTrue($this->access->hasFinanceCapability($finActor, $finProfile));
    }

    public function test_sameLegacyIdentityMapsDeterministically(): void
    {
        $a = $this->profiles->findByLegacyEmail('owner@tricab.com');
        $b = $this->profiles->findByLegacyLogin('owner');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertTrue($a->userId()->equals($b->userId()));
        self::assertSame($a->legacyEmail(), $b->legacyEmail());
    }

    public function test_moneyUsesDecimalSemantics(): void
    {
        $a = ExpenseMoney::fromString('12.50');
        $b = ExpenseMoney::fromString('12.500');
        self::assertTrue($a->equals($b));
        self::assertSame('12.5', $a->amount());
    }

    public function test_legacyRepositoryRejectsWrites(): void
    {
        $guard = new LegacyReadOnlySqlGuard();
        $source = new ReadOnlyLegacyExpenseReceiptSource(
            new class implements \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection {
                public function sourceLabel(): string { return 'test'; }
                public function query(string $sql, array $params = [], string $repository = 'unknown', string $method = 'unknown'): array { return []; }
            },
            $guard,
        );

        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $source->assertWritesRejected('UPDATE tc.triexpense_receipts SET status=\'x\'');
    }

    public function test_listReceiptsIgnoresOtherUserQuery(): void
    {
        $this->createService()->execute($this->owner, [
            'vendorName' => 'Mine', 'totalAmount' => '1', 'gstAmount' => '0', 'currency' => 'AUD', 'category' => '64100',
        ]);
        $list = new ListMyExpenseReceiptsService($this->access, $this->receipts, $this->assembler);
        $this->expectException(ExpenseAccessDenied::class);
        $list->execute($this->owner, 999);
    }

    public function test_approvalsQueueIsManagerScoped(): void
    {
        $this->pending();

        $queue = new ListExpenseApprovalsService($this->access, $this->receipts, $this->assembler);
        $mgrItems = $queue->execute($this->manager);
        $strangerItems = $queue->execute($this->stranger);
        self::assertCount(1, $mgrItems);
        self::assertCount(0, $strangerItems);
    }

    public function test_sendBackRequiresFinance(): void
    {
        $submitted = $this->pending();
        $approved = $this->approvalService()->approve($this->manager, $submitted->publicId, (string) $submitted->rowVersion);

        $this->expectException(ExpenseAccessDenied::class);
        $this->approvalService()->sendBack($this->manager, $approved->publicId, (string) $approved->rowVersion, 'fix note');
    }

    public function test_migrationDryRunClassifier(): void
    {
        $c = new ExpenseMigrationDryRunClassifier();
        self::assertSame(ExpenseMigrationReadiness::OWNER_UNMATCHED, $c->classify(['owner_matched' => false])->toString());
        self::assertSame(ExpenseMigrationReadiness::CATEGORY_REVIEW, $c->classify([
            'owner_matched' => true, 'manager_matched' => true, 'category' => 'Travel', 'file_url' => 'https://blob.core.windows.net/x',
        ])->toString());
        self::assertSame(ExpenseMigrationReadiness::READY, $c->classify([
            'owner_matched' => true, 'manager_matched' => true, 'category' => '64100', 'file_url' => 'https://x.blob.core.windows.net/a',
        ])->toString());
    }

    public function test_submitRequiresManager(): void
    {
        $orphan = UserId::fromInt(777);
        $this->profiles->save(new ExpenseProfile(
            $orphan, 'orphan', 'orphan@tricab.com', true, false, false, null, null,
        ));
        $actor = $this->actor($orphan, 'orp', 'orphan@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $created = (new CreateExpenseReceiptService(
            $this->access, $this->receipts, $this->managers, $this->audit, $this->assembler, $this->clock,
        ))->execute($actor, [
            'vendorName' => 'X', 'totalAmount' => '1', 'gstAmount' => '0', 'currency' => 'AUD', 'category' => '64100',
        ]);

        $this->expectException(ExpenseManagerRequired::class);
        $this->submitService()->execute($actor, $created->publicId, (string) $created->rowVersion);
    }

    public function test_submitPreservesFrozenManagerWhenProfileManagerChanges(): void
    {
        $created = $this->draft();
        $stored = $this->receipts->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId::fromString($created->publicId),
        );
        self::assertNotNull($stored);
        self::assertTrue($stored->managerUserId()?->equals($this->managerId));

        $this->profiles->save(new ExpenseProfile(
            $this->ownerId, 'owner', 'owner@tricab.com', true, false, false, 'stranger', $this->strangerId,
        ));

        $submitted = $this->submitService()->execute($this->owner, $created->publicId, (string) $created->rowVersion);
        $after = $this->receipts->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId::fromString($submitted->publicId),
        );
        self::assertNotNull($after);
        self::assertTrue($after->managerUserId()?->equals($this->managerId));
        self::assertFalse($after->managerUserId()?->equals($this->strangerId));
    }

    public function test_submitResolvesLegacyManagerLoginWhenManagerUserIdMissing(): void
    {
        $receipt = \TriCab\Core\Expense\Domain\Entity\ExpenseReceipt::createDraft(
            publicId: \TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId::generate(),
            ownerUserId: $this->ownerId,
            vendorName: 'Legacy Cafe',
            totalAmount: ExpenseMoney::fromString('10.00'),
            gstAmount: ExpenseMoney::fromString('0.91'),
            currency: \TriCab\Core\Expense\Domain\ValueObject\ExpenseCurrency::fromString('AUD'),
            gstType: Module::GST_INCLUSIVE,
            receiptDate: new \DateTimeImmutable('2026-09-01'),
            category: \TriCab\Core\Expense\Domain\ValueObject\ExpenseCategory::fromLegacy('66700', null),
            costCentre: 'Port Melbourne',
            description: null,
            attachment: null,
            now: $this->clock->now(),
            managerUserId: null,
            legacy: \TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseIdentity::of(
                createdBy: 'owner@tricab.com',
                managerLogin: 'mgr',
            ),
        );
        $saved = $this->receipts->save($receipt);
        $submitted = $this->submitService()->execute($this->owner, $saved->publicId()->toString(), (string) $saved->rowVersion()?->toBase64());
        $after = $this->receipts->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId::fromString($submitted->publicId),
        );
        self::assertNotNull($after);
        self::assertTrue($after->managerUserId()?->equals($this->managerId));
        self::assertSame('mgr', $after->legacy()->managerLogin());
    }

    public function test_ownerCannotApproveOwnReceipt(): void
    {
        $submitted = $this->pending();
        $this->expectException(ExpenseAccessDenied::class);
        $this->approvalService()->approve($this->owner, $submitted->publicId, (string) $submitted->rowVersion);
    }

    public function test_ownerCannotRejectOwnReceipt(): void
    {
        $submitted = $this->pending();
        $this->expectException(ExpenseAccessDenied::class);
        $this->approvalService()->reject($this->owner, $submitted->publicId, 'nope', (string) $submitted->rowVersion);
    }

    public function test_financeMayApproveOwnReceipt(): void
    {
        $financeId = UserId::fromInt(808);
        $this->profiles->save(new ExpenseProfile(
            $financeId, 'finown', 'finown@tricab.com', true, true, false, 'mgr', $this->managerId,
        ));
        $finActor = $this->actor($financeId, 'finown-pub', 'finown@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $created = $this->createService()->execute($finActor, [
            'vendorName' => 'Cafe', 'totalAmount' => '20.00', 'gstAmount' => '1.82', 'currency' => 'AUD', 'category' => '66700',
        ]);
        $submitted = $this->submitService()->execute($finActor, $created->publicId, (string) $created->rowVersion);
        $approved = $this->approvalService()->approve($finActor, $submitted->publicId, (string) $submitted->rowVersion);
        self::assertSame('approved', $approved->status);
    }

    public function test_teAdminWithoutFinanceCannotSelfApprove(): void
    {
        $adminId = UserId::fromInt(909);
        $this->profiles->save(new ExpenseProfile(
            $adminId, 'teadmin', 'teadmin@tricab.com', true, false, true, 'mgr', $this->managerId,
        ));
        $adminActor = $this->actor($adminId, 'teadmin-pub', 'teadmin@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $created = $this->createService()->execute($adminActor, [
            'vendorName' => 'Cafe', 'totalAmount' => '20.00', 'gstAmount' => '1.82', 'currency' => 'AUD', 'category' => '66700',
        ]);
        $submitted = $this->submitService()->execute($adminActor, $created->publicId, (string) $created->rowVersion);
        $this->expectException(ExpenseAccessDenied::class);
        $this->approvalService()->approve($adminActor, $submitted->publicId, (string) $submitted->rowVersion);
    }

    public function test_staleRowVersionConflictsOnUpdate(): void
    {
        $created = $this->draft();
        $update = new UpdateExpenseReceiptService($this->access, $this->receipts, $this->audit, $this->assembler, $this->clock);
        $update->execute($this->owner, $created->publicId, [
            'vendorName' => 'Updated Cafe',
            'expectedRowVersion' => $created->rowVersion,
        ]);

        $this->expectException(\TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict::class);
        $update->execute($this->owner, $created->publicId, [
            'vendorName' => 'Stale Cafe',
            'expectedRowVersion' => $created->rowVersion,
        ]);
    }

    public function test_ownerCanSoftDeleteDraftAndFinanceCanRestore(): void
    {
        $created = $this->draft();
        $deleted = (new SoftDeleteExpenseReceiptService($this->access, $this->receipts, $this->audit, $this->assembler, $this->clock))
            ->execute($this->owner, $created->publicId, (string) $created->rowVersion);
        self::assertNotNull($deleted->deletedAt);

        $financeId = UserId::fromInt(606);
        $this->profiles->save(new ExpenseProfile(
            $financeId, 'fin', 'fin-restore@tricab.com', true, true, false, 'mgr', $this->managerId,
        ));
        $finActor = $this->actor($financeId, 'fin-rest', 'fin-restore@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $restored = (new RestoreExpenseReceiptService($this->access, $this->receipts, $this->audit, $this->assembler, $this->clock))
            ->execute($finActor, $deleted->publicId, (string) $deleted->rowVersion);
        self::assertNull($restored->deletedAt);
        self::assertSame('draft', $restored->status);
    }

    public function test_ownerCannotSoftDeleteApprovedReceipt(): void
    {
        $submitted = $this->pending();
        $approved = $this->approvalService()->approve($this->manager, $submitted->publicId, (string) $submitted->rowVersion);
        $this->expectException(ExpenseAccessDenied::class);
        (new SoftDeleteExpenseReceiptService($this->access, $this->receipts, $this->audit, $this->assembler, $this->clock))
            ->execute($this->owner, $approved->publicId, (string) $approved->rowVersion);
    }

    public function test_financeCanPurgeSoftDeletedReceiptAndAttachment(): void
    {
        $attachments = new \TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore();
        $created = $this->draft();
        $ticket = (new InitiateExpenseAttachmentService($this->access, $this->receipts, $attachments))
            ->execute($this->owner, $created->publicId, 'lunch.jpg', 'image/jpeg', 1024);
        $attachments->put($ticket['blobPath']);
        $completed = (new CompleteExpenseAttachmentService($this->access, $this->receipts, $attachments, $this->audit, $this->assembler, $this->clock))
            ->execute($this->owner, $created->publicId, $ticket['blobPath'], 'lunch.jpg', (string) $created->rowVersion);

        $financeId = UserId::fromInt(707);
        $this->profiles->save(new ExpenseProfile(
            $financeId, 'finp', 'fin-purge@tricab.com', true, true, false, 'mgr', $this->managerId,
        ));
        $finActor = $this->actor($financeId, 'fin-purge', 'fin-purge@tricab.com', [Module::PERMISSION_ACCESS], ['employee']);
        $deleted = (new SoftDeleteExpenseReceiptService($this->access, $this->receipts, $this->audit, $this->assembler, $this->clock))
            ->execute($finActor, $completed->publicId, (string) $completed->rowVersion);

        (new PurgeExpenseReceiptService($this->access, $this->receipts, $attachments, $this->audit, $this->clock))
            ->execute($finActor, $deleted->publicId, (string) $deleted->rowVersion);
        self::assertNull($this->receipts->findByPublicId(
            \TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId::fromString($deleted->publicId),
        ));
        self::assertFalse($attachments->objectExists($ticket['blobPath']));
        $actions = array_column($this->audit->events(), 'action');
        self::assertContains('receipt_purged', $actions);
        self::assertContains('attachment_uploaded', $actions);
    }

    public function test_duplicateDetectionWarnsOnFuzzyVendorSameDateIgnoringAmount(): void
    {
        $this->createService()->execute($this->owner, [
            'vendorName' => 'Officeworks Pty Ltd',
            'totalAmount' => '50.00',
            'gstAmount' => '4.55',
            'currency' => 'AUD',
            'category' => '64100',
            'receiptDate' => '2026-09-01',
        ]);
        $checker = new CheckExpenseDuplicateService($this->access, $this->receipts, $this->assembler);
        $matches = $checker->execute($this->owner, 'Officeworks', '2026-09-01');
        self::assertCount(1, $matches);
        self::assertSame('50', $matches[0]['totalAmount']);
        $none = $checker->execute($this->owner, 'Bunnings', '2026-09-01');
        self::assertSame([], $none);
    }

    public function test_inclusiveGstIsTotalDividedByElevenRoundedToTwoDp(): void
    {
        $gst = \TriCab\Core\Expense\Domain\Service\ExpenseGstCalculator::inclusiveFromTotal(ExpenseMoney::fromString('12.50'));
        self::assertSame('1.14', $gst->amount());
        $created = $this->createService()->execute($this->owner, [
            'vendorName' => 'Gst Cafe',
            'totalAmount' => '11.00',
            'gstType' => Module::GST_INCLUSIVE,
            'currency' => 'AUD',
            'category' => '66700',
        ]);
        self::assertSame('1', $created->gstAmount);
        self::assertSame(Module::GST_INCLUSIVE, $created->gstType);
    }

    public function test_strangerCannotReadAttachment(): void
    {
        $attachments = new \TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore();
        $created = $this->draft();
        $ticket = (new InitiateExpenseAttachmentService($this->access, $this->receipts, $attachments))
            ->execute($this->owner, $created->publicId, 'lunch.png', 'image/png', 2048);
        $attachments->put($ticket['blobPath']);
        (new CompleteExpenseAttachmentService($this->access, $this->receipts, $attachments, $this->audit, $this->assembler, $this->clock))
            ->execute($this->owner, $created->publicId, $ticket['blobPath'], 'lunch.png', (string) $created->rowVersion);

        $this->expectException(ExpenseAccessDenied::class);
        (new GetExpenseAttachmentFileService($this->access, $this->receipts, $attachments))
            ->execute($this->stranger, $created->publicId);
    }

    public function test_wordpressAttachmentDoesNotIssueRawUrl(): void
    {
        $created = $this->createService()->execute($this->owner, [
            'vendorName' => 'Legacy File',
            'totalAmount' => '1.00',
            'gstAmount' => '0.09',
            'currency' => 'AUD',
            'category' => '64100',
            'attachmentUrl' => 'https://www.tricab.com/wp-content/uploads/expense/old.jpg',
            'attachmentFileName' => 'old.jpg',
        ]);
        self::assertSame('legacy_wordpress', $created->attachment['dualReadClass'] ?? null);
        $attachments = new \TriCab\Api\Expense\Persistence\InMemoryExpenseAttachmentStore();
        $this->expectException(\TriCab\Core\Expense\Application\Exception\ExpenseAttachmentUnavailable::class);
        (new GetExpenseAttachmentFileService($this->access, $this->receipts, $attachments))
            ->execute($this->owner, $created->publicId);
    }
}