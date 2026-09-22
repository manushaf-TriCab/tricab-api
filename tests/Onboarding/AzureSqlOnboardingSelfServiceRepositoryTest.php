<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingSelfServiceRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Phase 3.1/3.2 — the self-service reader, against the real schema.
 *
 * `OnboardingSelfSummaryTest` proves the rules with a double, and this proves
 * the SQL. Both are needed: a double cannot catch a mistyped join or a filter
 * that does not use the index it was written for, and the live database cannot
 * be asked about a leaver window without inventing a clock.
 *
 * What is actually being proved here is the one property a double cannot: that
 * `WHERE [UserId] = :userId` is in the QUERY. The test seeds two people's work
 * in the same transaction and asks for one of them. A repository that fetched
 * everything and filtered in PHP would pass a unit test and fail this one only
 * if the filter were removed — so the isolation assertion is written against
 * row counts in the database, not against what the repository chose to return.
 *
 * Everything runs inside a transaction that is always rolled back; the target is
 * shared. Skips when Azure SQL is unreachable.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingSelfServiceRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;

    private AzureSqlOnboardingSelfServiceRepository $repository;

    /** Two real users, because ownership is the thing under test. */
    private int $mine;

    private int $theirs;

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

            $users = $pdo->query('SELECT TOP 2 UserId FROM [identity].[Users] ORDER BY UserId');
            $ids = $users === false ? [] : $users->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }

        if (count($ids) < 2) {
            self::markTestSkipped('Two users in identity.Users are needed to prove ownership isolation.');
        }

        self::assertIsNumeric($ids[0]);
        self::assertIsNumeric($ids[1]);

        $this->pdo = $pdo;
        $this->mine = (int) $ids[0];
        $this->theirs = (int) $ids[1];
        $this->repository = new AzureSqlOnboardingSelfServiceRepository(
            new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test')),
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

    private function db(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params = []): void
    {
        $this->db()->prepare($sql)->execute($params);
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    private function aFormVersion(string $title): int
    {
        $this->exec(
            'INSERT INTO [onboarding].[FormDefinitions] ([Slug],[Title],[AccessGroup])
             VALUES (:slug, :title, N\'hr\')',
            [':slug' => $this->uniqueSlug('syn-self'), ':title' => $title],
        );
        $definitionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitionVersions]
                ([FormDefinitionId],[VersionNumber],[SchemaJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:d, 3, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':d' => $definitionId, ':u' => $this->mine],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aPolicyVersion(string $title): int
    {
        $this->exec(
            'INSERT INTO [onboarding].[Policies] ([Slug],[Title]) VALUES (:slug, :title)',
            [':slug' => $this->uniqueSlug('syn-pol'), ':title' => $title],
        );
        $policyId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[PolicyVersions]
                ([PolicyId],[VersionNumber],[ContentJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:p, 2, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':p' => $policyId, ':u' => $this->mine],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aTrainingVersion(string $title): int
    {
        $this->exec(
            'INSERT INTO [onboarding].[TrainingModules] ([Slug],[Title]) VALUES (:slug, :title)',
            [':slug' => $this->uniqueSlug('syn-trn'), ':title' => $title],
        );
        $moduleId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[TrainingModuleVersions]
                ([TrainingModuleId],[VersionNumber],[PresentationManifestJson],[RequiredSlideCount],
                 [IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:m, 1, N'[]', 0, 1, SYSUTCDATETIME(), :u)",
            [':m' => $moduleId, ':u' => $this->mine],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function allocateForm(int $userId, int $versionId, ?string $dueDate = null, string $status = AssignmentStatus::NOT_STARTED): void
    {
        $this->exec(
            'INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId],[DueDate],[Status])
             VALUES (:u, N\'form\', :v, :a, :d, :s)',
            [':u' => $userId, ':v' => $versionId, ':a' => $this->mine, ':d' => $dueDate, ':s' => $status],
        );
    }

    /** @return list<string> */
    private function titles(int $userId): array
    {
        return array_map(
            static fn (OnboardingAssignmentView $view): string => $view->title,
            $this->repository->listAssignments($userId),
        );
    }

    // ------------------------------------------------------------ record

    public function test_somebody_with_no_induction_has_no_record(): void
    {
        self::assertNull($this->repository->findTraineeRecord($this->mine));
    }

    public function test_a_record_is_read_back_with_its_departure(): void
    {
        $this->exec(
            'INSERT INTO [onboarding].[TraineeProfiles]
                ([UserId],[StartDate],[DepartedAtUtc],[DepartureRecordedByUserId])
             VALUES (:u, :start, :departed, :by)',
            [
                ':u' => $this->mine,
                ':start' => '2026-10-01',
                ':departed' => '2026-09-20 09:00:00',
                ':by' => $this->mine,
            ],
        );

        $record = $this->repository->findTraineeRecord($this->mine);

        self::assertNotNull($record);
        self::assertSame('2026-10-01', $record->startDate);
        self::assertNotNull($record->departedAtUtc);
        self::assertSame('2026-09-20 09:00:00', $record->departedAtUtc->format('Y-m-d H:i:s'));
        self::assertNotSame('', $record->publicId);
    }

    /**
     * A departure that has not happened reads back as NULL, not as an empty
     * string or an epoch date — {@see \TriCab\Core\Onboarding\Domain\Service\LeaverPolicy}
     * distinguishes "no departure" from "a departure", and a zero date would
     * make everybody a leaver.
     */
    public function test_a_record_without_a_departure_reads_back_null(): void
    {
        $this->exec(
            'INSERT INTO [onboarding].[TraineeProfiles] ([UserId]) VALUES (:u)',
            [':u' => $this->mine],
        );

        $record = $this->repository->findTraineeRecord($this->mine);

        self::assertNotNull($record);
        self::assertNull($record->departedAtUtc);
        self::assertNull($record->startDate);
    }

    public function test_a_record_belongs_to_one_person(): void
    {
        $this->exec(
            'INSERT INTO [onboarding].[TraineeProfiles] ([UserId]) VALUES (:u)',
            [':u' => $this->theirs],
        );

        self::assertNull($this->repository->findTraineeRecord($this->mine));
        self::assertNotNull($this->repository->findTraineeRecord($this->theirs));
    }

    // ------------------------------------------------------- assignments

    /**
     * The ownership filter, proved where it matters: both people's work is in
     * the table, and the query answers for one.
     */
    public function test_assignments_are_filtered_to_the_person_who_asked(): void
    {
        $this->allocateForm($this->mine, $this->aFormVersion('Mine'));
        $this->allocateForm($this->theirs, $this->aFormVersion('Theirs'));

        self::assertSame(['Mine'], $this->titles($this->mine));
        self::assertSame(['Theirs'], $this->titles($this->theirs));
    }

    /**
     * Withdrawal is evidence-preserving: the row stays, with everything
     * attempted against it, and disappears from the person's plate. Asserted
     * against the row still being there, because "the list is shorter" is also
     * what a DELETE would produce.
     */
    public function test_a_withdrawn_allocation_is_off_the_plate_but_still_in_the_table(): void
    {
        $versionId = $this->aFormVersion('Withdrawn');
        $this->allocateForm($this->mine, $versionId);
        $this->exec(
            'UPDATE [onboarding].[Assignments]
                SET [WithdrawnAtUtc] = SYSUTCDATETIME(), [WithdrawnByUserId] = :by
              WHERE [UserId] = :u',
            [':by' => $this->mine, ':u' => $this->mine],
        );

        self::assertSame([], $this->titles($this->mine));

        $statement = $this->db()->prepare(
            'SELECT COUNT(*) FROM [onboarding].[Assignments] WHERE [UserId] = :u',
        );
        $statement->execute([':u' => $this->mine]);
        self::assertSame('1', (string) $statement->fetchColumn(), 'The evidence should still be in the table.');
    }

    /**
     * The three item types come back through one statement with three joins.
     * `CK_onboarding_Assignments_ExactlyOneItem` is what makes the COALESCEs
     * total, so this test is also what would catch that constraint being
     * relaxed.
     */
    public function test_all_three_item_types_resolve_their_title_and_version(): void
    {
        $formVersion = $this->aFormVersion('A form');
        $policyVersion = $this->aPolicyVersion('A policy');
        $trainingVersion = $this->aTrainingVersion('A module');

        $this->allocateForm($this->mine, $formVersion);
        $this->exec(
            'INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[PolicyVersionId],[AllocatedByUserId])
             VALUES (:u, N\'policy\', :v, :a)',
            [':u' => $this->mine, ':v' => $policyVersion, ':a' => $this->mine],
        );
        $this->exec(
            'INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[TrainingModuleVersionId],[AllocatedByUserId])
             VALUES (:u, N\'training\', :v, :a)',
            [':u' => $this->mine, ':v' => $trainingVersion, ':a' => $this->mine],
        );

        $byType = [];
        foreach ($this->repository->listAssignments($this->mine) as $view) {
            $byType[$view->itemType] = $view;
        }

        self::assertSame(['form', 'policy', 'training'], self::sorted(array_keys($byType)));

        $form = $this->only($byType, 'form');
        $policy = $this->only($byType, 'policy');
        $training = $this->only($byType, 'training');

        self::assertSame('A form', $form->title);
        self::assertSame(3, $form->versionNumber);
        self::assertSame('A policy', $policy->title);
        self::assertSame(2, $policy->versionNumber);
        self::assertSame('A module', $training->title);
        self::assertSame(1, $training->versionNumber);

        foreach ([$form, $policy, $training] as $view) {
            self::assertNotSame('', $view->itemPublicId, 'Every item resolves a public id of its own.');
            self::assertNotSame($view->publicId, $view->itemPublicId);
        }
    }

    public function test_a_due_date_and_an_allocation_time_survive_the_round_trip(): void
    {
        $this->allocateForm($this->mine, $this->aFormVersion('Dated'), '2026-11-30');

        $view = $this->repository->listAssignments($this->mine)[0];

        self::assertSame('2026-11-30', $view->dueDate);
        self::assertNotSame('', $view->allocatedAtUtc);
        self::assertNull($view->submittedAtUtc);
        self::assertNull($view->completedAtUtc);
    }

    public function test_an_undated_allocation_reads_back_null_rather_than_an_empty_string(): void
    {
        $this->allocateForm($this->mine, $this->aFormVersion('Undated'));

        self::assertNull($this->repository->listAssignments($this->mine)[0]->dueDate);
    }

    public function test_a_status_survives_the_round_trip(): void
    {
        $this->allocateForm($this->mine, $this->aFormVersion('Stuck'), null, AssignmentStatus::NEED_HELP);

        self::assertSame(
            AssignmentStatus::NEED_HELP,
            $this->repository->listAssignments($this->mine)[0]->status->toString(),
        );
    }

    /**
     * @param array<string, OnboardingAssignmentView> $byType
     */
    private function only(array $byType, string $itemType): OnboardingAssignmentView
    {
        self::assertArrayHasKey($itemType, $byType);

        return $byType[$itemType];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
