<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAdminAuditLog;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingPeopleRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationSelection;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\Service\SetPersonAllocationService;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Phase 4.2 against `tricab-mel`, inside a transaction that is always rolled
 * back. Synthetic content only: every module, policy and profile here is
 * created by the test and gone when it ends.
 *
 * The allocation write needs `[audit].[OnboardingAdminEvents]`; those tests
 * skip, by name, until `create-onboarding-admin-audit.sql` is applied.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingPeopleRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private AzureSqlOnboardingPeopleRepository $people;
    private SetPersonAllocationService $service;
    private int $subject;
    private int $hr;
    private bool $auditApplied = false;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[onboarding].[Assignments]', N'U') IS NULL THEN 0 ELSE 1 END");
            if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
                self::markTestSkipped('The onboarding schema is not applied to this database.');
            }
            $audit = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[audit].[OnboardingAdminEvents]', N'U') IS NULL THEN 0 ELSE 1 END");
            $this->auditApplied = $audit !== false && (int) ($audit->fetchColumn() ?: 0) === 1;
            // Two people with no induction record, so the test owns both
            // profiles outright and cannot collide with a real one.
            $users = $pdo->query(
                'SELECT TOP 2 u.[UserId] FROM [identity].[Users] u
                 WHERE NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[UserId] = u.[UserId])
                 ORDER BY u.[UserId]',
            );
            $ids = $users === false ? [] : $users->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }

        if (count($ids) < 2) {
            self::markTestSkipped('Two users without an induction record are needed.');
        }
        self::assertIsNumeric($ids[0]);
        self::assertIsNumeric($ids[1]);

        $this->pdo = $pdo;
        $this->subject = (int) $ids[0];
        $this->hr = (int) $ids[1];

        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test'));
        $this->people = new AzureSqlOnboardingPeopleRepository($connection);
        $this->service = new SetPersonAllocationService(
            $this->people,
            new AzureSqlOnboardingAdminAuditLog($connection),
            new SystemClock(),
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

    // ------------------------------------------------------------------ reads

    public function test_the_catalogue_offers_only_active_published_live_versions(): void
    {
        $live = $this->policy('Live policy', published: true);
        $draft = $this->policy('Draft policy', published: false);
        $superseded = $this->policy('Superseded policy', published: true, superseded: true);

        $offered = array_map(static fn (AllocatableItem $i) => $i->itemPublicId, $this->people->listAllocatableItems());

        self::assertContains($live['itemPublicId'], $offered);
        self::assertNotContains($draft['itemPublicId'], $offered);
        self::assertNotContains($superseded['itemPublicId'], $offered);
    }

    public function test_evidence_is_read_from_the_evidence_tables_not_the_status(): void
    {
        $this->profile();
        $untouched = $this->policy('Untouched', published: true);
        $drafted = $this->form('Drafted');
        $this->assign('policy', $untouched['versionId']);
        $draftAssignment = $this->assign('form', $drafted['versionId']);
        $this->exec(
            "INSERT INTO [onboarding].[FormDrafts] ([AssignmentId], [DraftDataJson]) VALUES (:a, N'{}')",
            [':a' => $draftAssignment],
        );

        $byTitle = [];
        foreach ($this->people->listCurrentAllocations($this->subject) as $allocation) {
            $byTitle[$allocation->title] = $allocation;
        }

        self::assertFalse($byTitle['Untouched']->hasEvidence);
        self::assertTrue($byTitle['Untouched']->isUntouched());
        // Still not_started, but a draft exists: touched.
        self::assertSame('not_started', $byTitle['Drafted']->status->toString());
        self::assertTrue($byTitle['Drafted']->hasEvidence);
        self::assertFalse($byTitle['Drafted']->isUntouched());
    }

    public function test_the_roster_finds_the_person_and_counts_their_live_work(): void
    {
        $person = $this->profile();
        $policy = $this->policy('Counted', published: true);
        $this->assign('policy', $policy['versionId'], 'submitted');

        $email = $this->text('SELECT [EmailAddress] FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->subject]);
        $result = $this->people->listPeople(1, 5, $email);

        $mine = array_values(array_filter($result['items'], static fn ($p) => $p->publicId === $person));
        self::assertCount(1, $mine);
        self::assertSame(['submitted' => 1], $mine[0]->statusCounts);
        self::assertGreaterThanOrEqual(1, $result['total']);
    }

    // ----------------------------------------------------------------- writes

    public function test_a_save_allocates_withdraws_and_audits_in_one_unit(): void
    {
        $this->requireAudit();
        $person = $this->profile();
        $keep = $this->policy('Kept', published: true);
        $drop = $this->policy('Dropped', published: true);
        $new = $this->policy('New', published: true);
        $this->assign('policy', $keep['versionId'], 'in_progress');
        $dropped = $this->assign('policy', $drop['versionId']);

        $detail = $this->people->personDetail($person);
        self::assertNotNull($detail);

        $result = $this->service->set($this->actor(), $person, \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($detail->rowVersion), [
            AllocationSelection::of('policy', $new['itemPublicId'], '2026-10-31'),
        ]);

        // Allocated New, withdrew Dropped, kept Kept (started).
        self::assertCount(1, $result['plan']->allocate);
        self::assertCount(1, $result['plan']->withdraw);
        self::assertSame(['Kept'], array_map(static fn (CurrentAllocation $a) => $a->title, $result['plan']->kept));

        $titles = array_map(static fn (CurrentAllocation $a) => $a->title, $result['detail']->allocations);
        sort($titles);
        self::assertSame(['Kept', 'New'], $titles);

        // Withdrawal is a date, never a DELETE.
        self::assertSame(1, $this->number(
            'SELECT COUNT(*) FROM [onboarding].[Assignments] WHERE [AssignmentId] = :a AND [WithdrawnAtUtc] IS NOT NULL AND [WithdrawnByUserId] = :hr',
            [':a' => $dropped, ':hr' => $this->hr],
        ));

        $events = $this->auditRows();
        self::assertSame(['allocation.allocated', 'allocation.withdrawn'], array_column($events, 'EventType'));
        foreach ($events as $event) {
            self::assertSame('succeeded', $event['Outcome']);
            self::assertSame((string) $this->hr, self::str($event['ActorUserId']));
            self::assertSame('ONBOARDING.PEOPLE.MANAGE', $event['PermissionCode']);
            // Identifiers and dates only — no name or email of anybody.
            $email = $this->text('SELECT [EmailAddress] FROM [identity].[Users] WHERE [UserId] = :u', [':u' => $this->subject]);
            self::assertStringNotContainsString($email, self::str($event['DetailsJson']));
            self::assertStringNotContainsString($email, self::str($event['EventSummary']));
        }
        self::assertStringContainsString('"dueDate":"2026-10-31"', self::str($events[0]['DetailsJson']));
    }

    public function test_a_stale_token_writes_nothing(): void
    {
        $this->requireAudit();
        $person = $this->profile();
        $policy = $this->policy('Racy', published: true);
        $detail = $this->people->personDetail($person);
        self::assertNotNull($detail);
        $token = \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($detail->rowVersion);

        // Another HR save lands first and moves the token.
        $this->exec('UPDATE [onboarding].[TraineeProfiles] SET [Position] = N\'Moved\' WHERE [UserId] = :u', [':u' => $this->subject]);

        try {
            $this->service->set($this->actor(), $person, $token, [AllocationSelection::of('policy', $policy['itemPublicId'], null)]);
            self::fail('A stale token was accepted.');
        } catch (OnboardingWriteConflict) {
        }

        self::assertSame([], $this->people->listCurrentAllocations($this->subject));
        self::assertSame([], $this->auditRows());
    }

    public function test_a_leaver_refusal_is_audited_as_refused(): void
    {
        $this->requireAudit();
        $person = $this->profile(departed: true);
        $policy = $this->policy('For a leaver', published: true);
        $detail = $this->people->personDetail($person);
        self::assertNotNull($detail);

        try {
            $this->service->set(
                $this->actor(),
                $person,
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($detail->rowVersion),
                [AllocationSelection::of('policy', $policy['itemPublicId'], null)],
            );
            self::fail('A leaver was allocated new work.');
        } catch (AllocationNotPermitted) {
        }

        $events = $this->auditRows();
        self::assertCount(1, $events);
        self::assertSame('allocation.refused', $events[0]['EventType']);
        self::assertSame('refused', $events[0]['Outcome']);
        self::assertSame('{"reason":"departed"}', $events[0]['DetailsJson']);
    }

    public function test_the_audit_table_cannot_be_edited(): void
    {
        $this->requireAudit();

        $this->expectException(PDOException::class);
        $this->exec('UPDATE [audit].[OnboardingAdminEvents] SET [EventSummary] = N\'edited\' WHERE 1 = 0', []);
    }

    // ---------------------------------------------------------------- helpers

    private function requireAudit(): void
    {
        if (!$this->auditApplied) {
            self::markTestSkipped('audit.OnboardingAdminEvents is not applied: run create-onboarding-admin-audit.sql (owner approval required).');
        }
    }

    private function actor(): ActorContext
    {
        return new ActorContext($this->hr, 'hr-public-id', 'hr@example.test', 'test-correlation');
    }

    private function profile(bool $departed = false): string
    {
        $this->exec(
            $departed
                ? 'INSERT INTO [onboarding].[TraineeProfiles] ([UserId], [DepartedAtUtc], [DepartureRecordedByUserId]) VALUES (:u, DATEADD(DAY, -30, SYSUTCDATETIME()), :hr)'
                : 'INSERT INTO [onboarding].[TraineeProfiles] ([UserId]) VALUES (:u)',
            $departed ? [':u' => $this->subject, ':hr' => $this->hr] : [':u' => $this->subject],
        );

        return strtolower($this->text(
            'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[TraineeProfiles] WHERE [UserId] = :u',
            [':u' => $this->subject],
        ));
    }

    /**
     * @return array{itemPublicId: string, versionId: int}
     */
    private function policy(string $title, bool $published, bool $superseded = false): array
    {
        $this->exec(
            'INSERT INTO [onboarding].[Policies] ([Slug], [Title]) VALUES (:slug, :title)',
            [':slug' => 'syn-p-' . bin2hex(random_bytes(6)), ':title' => $title],
        );
        $policyId = (int) $this->db()->lastInsertId();

        $this->exec(
            $published
                ? "INSERT INTO [onboarding].[PolicyVersions] ([PolicyId], [VersionNumber], [ContentJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId], [SupersededAtUtc])
                   VALUES (:p, 1, N'{\"blocks\":[]}', 1, SYSUTCDATETIME(), :u, " . ($superseded ? 'SYSUTCDATETIME()' : 'NULL') . ')'
                : "INSERT INTO [onboarding].[PolicyVersions] ([PolicyId], [VersionNumber], [ContentJson]) VALUES (:p, 1, N'{\"blocks\":[]}')",
            $published ? [':p' => $policyId, ':u' => $this->hr] : [':p' => $policyId],
        );

        return [
            'itemPublicId' => strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[Policies] WHERE [PolicyId] = :p', [':p' => $policyId])),
            'versionId' => (int) $this->db()->lastInsertId(),
        ];
    }

    /**
     * @return array{itemPublicId: string, versionId: int}
     */
    private function form(string $title): array
    {
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug], [Title], [AccessGroup]) VALUES (:slug, :title, N'hr')",
            [':slug' => 'syn-f-' . bin2hex(random_bytes(6)), ':title' => $title],
        );
        $definitionId = (int) $this->db()->lastInsertId();
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitionVersions] ([FormDefinitionId], [VersionNumber], [SchemaJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId])
             VALUES (:d, 1, N'{\"fields\":[]}', 1, SYSUTCDATETIME(), :u)",
            [':d' => $definitionId, ':u' => $this->hr],
        );

        return [
            'itemPublicId' => strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[FormDefinitions] WHERE [FormDefinitionId] = :d', [':d' => $definitionId])),
            'versionId' => (int) $this->db()->lastInsertId(),
        ];
    }

    private function assign(string $itemType, int $versionId, string $status = 'not_started'): int
    {
        $column = ['training' => 'TrainingModuleVersionId', 'policy' => 'PolicyVersionId', 'form' => 'FormDefinitionVersionId'][$itemType];
        $this->exec(
            "INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [{$column}], [Status], [AllocatedByUserId])
             VALUES (:u, :t, :v, :s, :a)",
            [':u' => $this->subject, ':t' => $itemType, ':v' => $versionId, ':s' => $status, ':a' => $this->hr],
        );

        return (int) $this->db()->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(): array
    {
        $statement = $this->db()->prepare(
            'SELECT [EventType], [Outcome], [ActorUserId], [PermissionCode], [EventSummary], [DetailsJson]
             FROM [audit].[OnboardingAdminEvents] WHERE [SubjectUserId] = :u ORDER BY [OnboardingAdminEventId]',
        );
        $statement->execute([':u' => $this->subject]);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db()->prepare($sql)->execute($params);
    }

    /** @param array<string, mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /** @param array<string, mixed> $params */
    private function text(string $sql, array $params = []): string
    {
        return self::str($this->scalar($sql, $params));
    }

    /** @param array<string, mixed> $params */
    private function number(string $sql, array $params = []): int
    {
        $value = $this->scalar($sql, $params);
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
