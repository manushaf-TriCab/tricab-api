<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingReportRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OnboardingReportSummary;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;
use TriCab\Core\Onboarding\Domain\Service\OverduePolicy;

/**
 * Phase 4.5 against `tricab-mel`, inside a transaction that is always rolled
 * back. The reports read the whole module, and the shared database holds demo
 * data, so the summary is checked as a difference: read, add synthetic rows,
 * read again.
 *
 * Three users with no induction record, as the other live tests pick them:
 * a current starter, a leaver, and the HR user the rows are attributed to.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingReportRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private AzureSqlOnboardingReportRepository $reports;
    private int $current;
    private int $leaver;
    private int $hr;
    private DateTimeImmutable $now;
    private string $today;

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
            $users = $pdo->query(
                'SELECT TOP 3 u.[UserId] FROM [identity].[Users] u
                 WHERE NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[UserId] = u.[UserId])
                 ORDER BY u.[UserId]',
            );
            $ids = $users === false ? [] : $users->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }

        if (count($ids) < 3) {
            self::markTestSkipped('Three users without an induction record are needed.');
        }
        self::assertIsNumeric($ids[0]);
        self::assertIsNumeric($ids[1]);
        self::assertIsNumeric($ids[2]);

        $this->pdo = $pdo;
        [$this->current, $this->leaver, $this->hr] = [(int) $ids[0], (int) $ids[1], (int) $ids[2]];
        $this->reports = new AzureSqlOnboardingReportRepository(new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test')));
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->today = OverduePolicy::todayFor($this->now);

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
    }

    public function test_the_summary_moves_by_exactly_the_synthetic_rows(): void
    {
        $before = $this->reports->summary($this->now, $this->today);
        $this->seed();
        $after = $this->reports->summary($this->now, $this->today);

        self::assertSame($this->today, $after->asOf);
        self::assertSame([
            'current' => 1,
            'departed' => 1,
            'notYetInvited' => 1,
            'nothingAllocated' => 0,
            'finished' => 0,
            'not_started' => 1,
            'in_progress' => 0,
            'submitted' => 1,
            'overdue' => 1,
            'waitingOnReview' => 1,
            'needHelp' => 0,
        ], self::diff($before, $after), 'the leaver adds a departed person and nothing else — their in-progress work is not counted');
    }

    public function test_item_compliance_counts_the_synthetic_items_without_the_leaver(): void
    {
        [$policy, $form] = $this->seed();

        $rows = [];
        for ($page = 1; $page <= 50; ++$page) {
            $result = $this->reports->itemCompliance($this->now, $this->today, $page, 100);
            foreach ($result['items'] as $row) {
                $rows[$row->itemPublicId] = $row;
            }
            if (count($result['items']) < 100) {
                break;
            }
        }

        self::assertArrayHasKey($policy, $rows);
        self::assertArrayHasKey($form, $rows);
        self::assertSame([1, 0, 0, 0, 1, 1, true], self::counts($rows[$policy]));
        self::assertSame([1, 0, 1, 0, 0, 0, true], self::counts($rows[$form]));
        self::assertSame('Synthetic report policy', $rows[$policy]->title);
    }

    public function test_the_overdue_list_names_the_current_starter_only_and_pages_past_the_end(): void
    {
        [$policy] = $this->seed();
        $person = strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[TraineeProfiles] WHERE [UserId] = :u', [':u' => $this->current]));
        $leaver = strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[TraineeProfiles] WHERE [UserId] = :u', [':u' => $this->leaver]));

        $first = $this->reports->overdueWork($this->now, $this->today, 1, 100);
        $all = $first['items'];
        for ($page = 2; count($all) < $first['total'] && $page <= 50; ++$page) {
            $all = [...$all, ...$this->reports->overdueWork($this->now, $this->today, $page, 100)['items']];
        }

        $mine = array_values(array_filter($all, static fn (OverdueWorkRow $r) => $r->itemPublicId === $policy));
        self::assertCount(1, $mine, 'the leaver\'s overdue copy of the same policy is not listed');
        self::assertSame($person, $mine[0]->personPublicId);
        self::assertNotContains($leaver, array_map(static fn (OverdueWorkRow $r) => $r->personPublicId, $all));
        self::assertSame('2020-01-02', $mine[0]->dueDate);
        self::assertSame('not_started', $mine[0]->status->toString());
        self::assertSame(OverduePolicy::daysOverdue('2020-01-02', $this->today), $mine[0]->daysOverdue);

        // Oldest first: nothing listed is due after anything listed later.
        $dates = array_map(static fn (OverdueWorkRow $r) => $r->dueDate, $all);
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates);

        $past = $this->reports->overdueWork($this->now, $this->today, 1000, 100);
        self::assertSame([], $past['items']);
        self::assertSame($first['total'], $past['total']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * The current starter holds the policy (overdue, not started) and the form
     * (submitted, long past due — waiting on HR, so not overdue). The leaver
     * holds the same policy, in progress and overdue.
     *
     * @return array{string, string} the policy and form public ids
     */
    private function seed(): array
    {
        $this->exec('INSERT INTO [onboarding].[TraineeProfiles] ([UserId]) VALUES (:u)', [':u' => $this->current]);
        $this->exec(
            'INSERT INTO [onboarding].[TraineeProfiles] ([UserId], [DepartedAtUtc], [DepartureRecordedByUserId], [InvitedAtUtc]) VALUES (:u, DATEADD(DAY, -30, SYSUTCDATETIME()), :hr, DATEADD(DAY, -60, SYSUTCDATETIME()))',
            [':u' => $this->leaver, ':hr' => $this->hr],
        );

        $this->exec('INSERT INTO [onboarding].[Policies] ([Slug], [Title]) VALUES (:slug, :title)', [':slug' => 'syn-p-' . bin2hex(random_bytes(6)), ':title' => 'Synthetic report policy']);
        $policyId = (int) $this->db()->lastInsertId();
        $this->exec(
            "INSERT INTO [onboarding].[PolicyVersions] ([PolicyId], [VersionNumber], [ContentJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId]) VALUES (:p, 1, N'{\"blocks\":[]}', 1, SYSUTCDATETIME(), :u)",
            [':p' => $policyId, ':u' => $this->hr],
        );
        $policyVersion = (int) $this->db()->lastInsertId();

        $this->exec("INSERT INTO [onboarding].[FormDefinitions] ([Slug], [Title], [AccessGroup]) VALUES (:slug, :title, N'hr')", [':slug' => 'syn-f-' . bin2hex(random_bytes(6)), ':title' => 'Synthetic report form']);
        $formId = (int) $this->db()->lastInsertId();
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitionVersions] ([FormDefinitionId], [VersionNumber], [SchemaJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId]) VALUES (:d, 1, N'{\"fields\":[]}', 1, SYSUTCDATETIME(), :u)",
            [':d' => $formId, ':u' => $this->hr],
        );
        $formVersion = (int) $this->db()->lastInsertId();

        $this->assign($this->current, 'policy', $policyVersion, 'not_started', '2020-01-02');
        $this->assign($this->current, 'form', $formVersion, 'submitted', '2020-01-01');
        $this->assign($this->leaver, 'policy', $policyVersion, 'in_progress', '2020-01-01');

        return [
            strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[Policies] WHERE [PolicyId] = :p', [':p' => $policyId])),
            strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[FormDefinitions] WHERE [FormDefinitionId] = :d', [':d' => $formId])),
        ];
    }

    private function assign(int $userId, string $itemType, int $versionId, string $status, string $dueDate): void
    {
        $column = ['policy' => 'PolicyVersionId', 'form' => 'FormDefinitionVersionId'][$itemType];
        $submitted = $status === 'submitted' ? 'SYSUTCDATETIME()' : 'NULL';
        $this->exec(
            "INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [{$column}], [Status], [AllocatedByUserId], [DueDate], [SubmittedAtUtc])
             VALUES (:u, :t, :v, :s, :a, :d, {$submitted})",
            [':u' => $userId, ':t' => $itemType, ':v' => $versionId, ':s' => $status, ':a' => $this->hr, ':d' => $dueDate],
        );
    }

    /**
     * @return array<string, int>
     */
    private static function diff(OnboardingReportSummary $before, OnboardingReportSummary $after): array
    {
        $status = static fn (OnboardingReportSummary $s, string $code): int => $s->workByStatus[$code] ?? 0;

        return [
            'current' => $after->currentPeople - $before->currentPeople,
            'departed' => $after->departedPeople - $before->departedPeople,
            'notYetInvited' => $after->notYetInvited - $before->notYetInvited,
            'nothingAllocated' => $after->nothingAllocated - $before->nothingAllocated,
            'finished' => $after->finished - $before->finished,
            'not_started' => $status($after, 'not_started') - $status($before, 'not_started'),
            'in_progress' => $status($after, 'in_progress') - $status($before, 'in_progress'),
            'submitted' => $status($after, 'submitted') - $status($before, 'submitted'),
            'overdue' => $after->overdue - $before->overdue,
            'waitingOnReview' => $after->waitingOnReview - $before->waitingOnReview,
            'needHelp' => $after->needHelp - $before->needHelp,
        ];
    }

    /**
     * @return array{int, int, int, int, int, int, bool}
     */
    private static function counts(ItemComplianceRow $row): array
    {
        return [$row->assigned, $row->completed, $row->waitingOnReview, $row->inProgress, $row->notStarted, $row->overdue, $row->offered];
    }

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db()->prepare($sql)->execute($params);
    }

    /** @param array<string, mixed> $params */
    private function text(string $sql, array $params): string
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        self::assertTrue(is_scalar($value));

        return (string) $value;
    }

    private function db(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }
}
