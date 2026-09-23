<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Persistence\AzureSqlRoleManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementAuditRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementRepository;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAccountDirectory;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAdminAuditLog;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingPeopleRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeUserStatusService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\UserManagementAuditRecorder;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Application\Service\CreateOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\DeleteOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\UpdateOnboardingPersonProfileService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonAlreadyExists;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Phase 4.1 against `tricab-mel`, inside a transaction that is always rolled
 * back. The new account's address is `@example.test` and random, so it can
 * collide with nobody; the linked account is one with no induction record.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingPersonLifecycleTest extends TestCase
{
    private ?PDO $pdo = null;
    private AzureSqlOnboardingPeopleRepository $people;
    private AzureSqlOnboardingAccountDirectory $accounts;
    private CreateOnboardingPersonService $create;
    private int $hr;
    private int $existing;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[audit].[OnboardingAdminEvents]', N'U') IS NULL OR OBJECT_ID(N'[identity].[UserEmailAddresses]', N'U') IS NULL THEN 0 ELSE 1 END");
            if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
                self::markTestSkipped('The HR audit table and identity.UserEmailAddresses are needed.');
            }
            $users = $pdo->query(
                "SELECT TOP 2 u.[UserId] FROM [identity].[Users] u
                 JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
                 WHERE NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[UserId] = u.[UserId])
                   AND s.[StatusCode] = N'pending'
                 ORDER BY u.[UserId]",
            );
            $ids = $users === false ? [] : $users->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }
        if (count($ids) < 2) {
            self::markTestSkipped('Two pending users without an induction record are needed.');
        }
        self::assertIsNumeric($ids[0]);
        self::assertIsNumeric($ids[1]);

        $this->pdo = $pdo;
        $this->hr = (int) $ids[0];
        $this->existing = (int) $ids[1];

        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test'));
        $this->people = new AzureSqlOnboardingPeopleRepository($connection);
        $this->accounts = new AzureSqlOnboardingAccountDirectory($connection, new CreateUserService(
            new AzureSqlUserManagementRepository($connection),
            new AzureSqlRoleManagementRepository($connection),
            new UserManagementAuditRecorder(new AzureSqlUserManagementAuditRepository($connection)),
        ), new ChangeUserStatusService(
            new AzureSqlUserManagementRepository($connection),
            new UserManagementAuditRecorder(new AzureSqlUserManagementAuditRepository($connection)),
        ));
        $this->create = new CreateOnboardingPersonService(
            $this->accounts,
            $this->people,
            new AzureSqlOnboardingAdminAuditLog($connection),
            // The outer test transaction is the unit here.
            new class () implements ApplicationTransaction {
                public function run(callable $operation): mixed
                {
                    return $operation();
                }
            },
            new UuidV4Generator(),
        );

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
    }

    public function test_a_new_address_becomes_an_active_guest_account_with_a_personal_address(): void
    {
        $email = 'syn-onboarding-' . bin2hex(random_bytes(5)) . '@example.test';

        $result = $this->create->create($this->actor(), $email, 'Synthetic Starter', OnboardingProfileFields::of('Storeperson', null, 'Dandenong', '2026-10-06'));

        self::assertTrue($result['accountCreated']);
        self::assertTrue($result['detail']->canSignIn, 'active + GUEST carries account.login');
        $userId = $this->number('SELECT [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :e', [':e' => $email]);

        self::assertSame('active', $this->text(
            'SELECT s.[StatusCode] FROM [identity].[Users] u JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] WHERE u.[UserId] = :u',
            [':u' => $userId],
        ));
        self::assertSame(['GUEST'], $this->column(
            'SELECT UPPER(r.[RoleCode]) FROM [identity].[UserRoles] ur JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] WHERE ur.[UserId] = :u AND ur.[IsActive] = 1',
            [':u' => $userId],
        ));
        self::assertSame('personal', $this->text('SELECT [Purpose] FROM [identity].[UserEmailAddresses] WHERE [UserId] = :u', [':u' => $userId]));
        self::assertSame(1, $this->number(
            "SELECT COUNT(*) FROM [audit].[UserManagementEvents] WHERE [TargetUserId] = :u AND [ActorUserId] = :a",
            [':u' => $userId, ':a' => $this->hr],
        ), 'the account creation is on the User Management trail, against the HR user');

        $admin = $this->text("SELECT [DetailsJson] FROM [audit].[OnboardingAdminEvents] WHERE [SubjectUserId] = :u AND [EventType] = N'person.created'", [':u' => $userId]);
        self::assertSame('{"accountCreated":true}', $admin);
        self::assertSame('Storeperson', $result['detail']->person->position);
    }

    /**
     * Deleting a record made by mistake: the record and its untouched
     * allocations go; the GUEST account made for it is disabled through User
     * Management, not deleted, because the audit rows naming it are
     * append-only. A linked account is left exactly as it was.
     */
    public function test_deleting_a_mistaken_record_disables_the_account_onboarding_made_and_leaves_a_linked_one(): void
    {
        $actor = new ActorContext(
            $this->hr,
            $this->text('SELECT LOWER(CONVERT(NVARCHAR(36), [PublicId])) FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->hr]),
            'hr@example.test',
            'test-correlation',
        );
        $delete = new DeleteOnboardingPersonService($this->people, $this->accounts, new AzureSqlOnboardingAdminAuditLog($this->connection()), new class () implements ApplicationTransaction {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        });

        $email = 'syn-onboarding-' . bin2hex(random_bytes(5)) . '@example.test';
        $made = $this->create->create($actor, $email, 'Synthetic Mistake', OnboardingProfileFields::of(null, null, null, null));
        $publicId = $made['detail']->person->publicId;
        $userId = $this->number('SELECT [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :e', [':e' => $email]);
        $record = $this->people->findPerson($publicId);
        self::assertNotNull($record);
        self::assertSame([], $this->people->recordedWork($userId));
        self::assertTrue($this->accounts->isOnboardingOnlyAccount($userId));

        self::assertSame(['accountDisabled' => true], $delete->delete($actor, $publicId, $record->rowVersion));

        self::assertNull($this->people->findPerson($publicId));
        self::assertSame('disabled', $this->text(
            'SELECT s.[StatusCode] FROM [identity].[Users] u JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] WHERE u.[UserId] = :u',
            [':u' => $userId],
        ));
        self::assertSame(1, $this->number(
            "SELECT COUNT(*) FROM [audit].[OnboardingAdminEvents] WHERE [SubjectUserId] = :u AND [EventType] = N'person.deleted' AND [Outcome] = N'succeeded'",
            [':u' => $userId],
        ));

        $linked = $this->create->create($actor, $this->text('SELECT [EmailAddress] FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->existing]), '', OnboardingProfileFields::of(null, null, null, null));
        $linkedRecord = $this->people->findPerson($linked['detail']->person->publicId);
        self::assertNotNull($linkedRecord);
        self::assertFalse($this->accounts->isOnboardingOnlyAccount($this->existing));
        self::assertSame(['accountDisabled' => false], $delete->delete($actor, $linkedRecord->publicId, $linkedRecord->rowVersion));
        self::assertSame('pending', $this->text(
            'SELECT s.[StatusCode] FROM [identity].[Users] u JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] WHERE u.[UserId] = :u',
            [':u' => $this->existing],
        ));
    }

    public function test_an_existing_pending_account_is_linked_and_not_touched(): void
    {
        $before = $this->row(
            'SELECT u.[UserStatusId], u.[DisplayName], (SELECT COUNT(*) FROM [identity].[UserRoles] ur WHERE ur.[UserId] = u.[UserId]) AS [Roles] FROM [identity].[Users] u WHERE u.[UserId] = :u',
            [':u' => $this->existing],
        );
        $email = $this->text('SELECT [EmailAddress] FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->existing]);

        $result = $this->create->create($this->actor(), $email, 'A Different Name', OnboardingProfileFields::of(null, null, null, null));

        self::assertFalse($result['accountCreated']);
        self::assertFalse($result['detail']->canSignIn, 'pending stays pending');
        self::assertSame($before, $this->row(
            'SELECT u.[UserStatusId], u.[DisplayName], (SELECT COUNT(*) FROM [identity].[UserRoles] ur WHERE ur.[UserId] = u.[UserId]) AS [Roles] FROM [identity].[Users] u WHERE u.[UserId] = :u',
            [':u' => $this->existing],
        ));

        try {
            $this->create->create($this->actor(), $email, '', OnboardingProfileFields::of(null, null, null, null));
            self::fail('Created a second induction record.');
        } catch (OnboardingPersonAlreadyExists) {
        }
    }

    public function test_a_last_day_is_stored_as_the_departure_instant_and_attributed(): void
    {
        $email = $this->text('SELECT [EmailAddress] FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->existing]);
        $detail = $this->create->create($this->actor(), $email, '', OnboardingProfileFields::of('Driver', null, null, null))['detail'];

        $after = (new UpdateOnboardingPersonProfileService($this->people, $this->accounts))->update(
            $this->actor(),
            $detail->person->publicId,
            RowVersionToken::fromBase64($detail->rowVersion),
            OnboardingProfileFields::of('Driver', 'Logistics', null, null),
            '2026-10-31',
        );

        self::assertSame('2026-10-31', $after->person->lastDay());
        self::assertSame('Logistics', $after->person->department);
        self::assertSame(['2026-10-31 13:00:00', (string) $this->hr], array_values(array_map(self::str(...), $this->row(
            'SELECT CONVERT(VARCHAR(19), [DepartedAtUtc], 120) AS [D], [DepartureRecordedByUserId] FROM [onboarding].[TraineeProfiles] WHERE [UserId] = :u',
            [':u' => $this->existing],
        ))));
    }

    public function test_an_invitation_claim_is_once_per_cooldown_and_can_be_put_back(): void
    {
        $email = $this->text('SELECT [EmailAddress] FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->existing]);
        $detail = $this->create->create($this->actor(), $email, '', OnboardingProfileFields::of(null, null, null, null))['detail'];
        $record = $this->people->findPerson($detail->person->publicId);
        self::assertNotNull($record);
        self::assertSame($email, $record->emailAddress);

        $rows = fn (string $type): int => $this->number(
            'SELECT COUNT(*) FROM [audit].[OnboardingAdminEvents] WHERE [SubjectUserId] = :u AND [EventType] = :t',
            [':u' => $this->existing, ':t' => $type],
        );
        $invited = OnboardingAdminEvent::succeeded(AdminEventType::PERSON_INVITED, $this->existing, $record->publicId, 'Synthetic invitation.', [], 'ONBOARDING.PEOPLE.MANAGE');
        $refused = OnboardingAdminEvent::refused(AdminEventType::PERSON_INVITATION_REFUSED, $this->existing, $record->publicId, 'Synthetic refusal.', ['reason' => 'delivery_failed'], 'ONBOARDING.PEOPLE.MANAGE');

        $first = $this->people->claimInvitation($record, 60, $invited, $this->actor());
        self::assertSame(['previousInvitedAtUtc' => null, 'previousInvitedByUserId' => null], $first);
        self::assertSame(1, $rows('person.invited'), 'the success row is written with the stamp');
        self::assertNull($this->people->claimInvitation($record, 60, $invited, $this->actor()), 'the second click loses');
        self::assertSame(1, $rows('person.invited'), 'and a lost click writes no row');

        $this->people->releaseInvitation($record, $first, $refused, $this->actor());
        self::assertNull($this->people->personDetail($detail->person->publicId)?->person->invitedAtUtc);
        self::assertSame(1, $rows('person.invitation_refused'), 'the refusal is written with the stamp put back');
        self::assertNotNull($this->people->claimInvitation($record, 60, $invited, $this->actor()), 'released, so claimable again');
    }

    // ---------------------------------------------------------------- helpers

    private function connection(): PdoSqlConnection
    {
        self::assertNotNull($this->pdo);

        return new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics('test'));
    }

    private function actor(): ActorContext
    {
        return new ActorContext($this->hr, 'hr-public-id', 'hr@example.test', 'test-correlation');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function row(string $sql, array $params): array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, $sql);

        /** @var array<string, mixed> */
        return $row;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function column(string $sql, array $params): array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return array_values(array_map(self::str(...), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $params */
    private function text(string $sql, array $params = []): string
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return self::str($statement->fetchColumn());
    }

    /** @param array<string, mixed> $params */
    private function number(string $sql, array $params = []): int
    {
        $value = $this->text($sql, $params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function str(mixed $value): string
    {
        self::assertTrue($value === null || is_scalar($value));

        return (string) $value;
    }

    private function db(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }
}
