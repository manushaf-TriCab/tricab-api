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
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingReminderRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OutstandingReminderItem;
use TriCab\Core\Onboarding\Domain\Service\ReminderPolicy;

/**
 * Phase 5.2 against `tricab-mel`, inside a transaction that is always rolled
 * back. Four users with no induction record: an invited starter, one never
 * invited, a leaver, and the HR user the rows are attributed to.
 *
 * Held here: only invited, current people are candidates; withdrawn and
 * finished work is not; and `NotificationLog` claims once per item and day,
 * re-claims a failed row, and claims afresh the next day (A8).
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingReminderRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private AzureSqlOnboardingReminderRepository $reminders;
    private int $invited;
    private int $uninvited;
    private int $leaver;
    private int $hr;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[onboarding].[NotificationLog]', N'U') IS NULL THEN 0 ELSE 1 END");
            if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
                self::markTestSkipped('The onboarding schema is not applied to this database.');
            }
            $users = $pdo->query(
                'SELECT TOP 4 u.[UserId] FROM [identity].[Users] u
                 WHERE NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] t WHERE t.[UserId] = u.[UserId])
                   AND NOT EXISTS (SELECT 1 FROM [onboarding].[NotificationLog] n WHERE n.[UserId] = u.[UserId])
                 ORDER BY u.[UserId]',
            );
            $ids = $users === false ? [] : $users->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }

        if (count($ids) < 4) {
            self::markTestSkipped('Four users without an induction record are needed.');
        }

        self::assertIsNumeric($ids[0]);
        self::assertIsNumeric($ids[1]);
        self::assertIsNumeric($ids[2]);
        self::assertIsNumeric($ids[3]);

        $this->pdo = $pdo;
        [$this->invited, $this->uninvited, $this->leaver, $this->hr] = [(int) $ids[0], (int) $ids[1], (int) $ids[2], (int) $ids[3]];
        $this->reminders = new AzureSqlOnboardingReminderRepository(new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test')));
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
    }

    public function test_only_live_reminded_work_of_invited_current_people_is_a_candidate(): void
    {
        $this->seed();

        $mine = $this->candidatesFor([$this->invited, $this->uninvited, $this->leaver]);

        self::assertSame(
            [
                [$this->invited, 'Synthetic reminder policy', 'not_started', '2020-01-02'],
                [$this->invited, 'Synthetic reminder policy B', 'fix_and_resubmit', null],
            ],
            array_map(static fn (OutstandingReminderItem $i) => [$i->userId, $i->title, $i->status, $i->dueDate], $mine),
            'dated before undated; nothing for the uninvited person, the leaver, or completed, need-help and withdrawn work',
        );
        self::assertSame('policy', $mine[0]->itemType);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $mine[0]->assignmentPublicId);
    }

    public function test_a_claim_is_once_per_item_and_day_and_a_failed_row_is_claimed_again(): void
    {
        $this->seed();
        $item = $this->candidatesFor([$this->invited])[0];
        $claim = fn (string $day): ?int => $this->reminders->claim($this->invited, ReminderPolicy::TYPE_OVERDUE, $item->itemType, $item->assignmentPublicId, $day);

        $first = $claim('2026-09-24');
        self::assertNotNull($first);
        self::assertNull($claim('2026-09-24'), 'the same item the same day is already claimed');

        $this->reminders->markFailed([$first], 'RuntimeException');
        self::assertSame(['failed', 'RuntimeException'], $this->outcome($first));

        self::assertSame($first, $claim('2026-09-24'), 'a failed row is re-claimed, not duplicated');
        self::assertSame(['sent', null], $this->outcome($first));

        $nextDay = $claim('2026-09-25');
        self::assertNotNull($nextDay);
        self::assertNotSame($first, $nextDay);
    }

    // --------------------------------------------------------------- helpers

    private function seed(): void
    {
        $this->exec('INSERT INTO [onboarding].[TraineeProfiles] ([UserId], [InvitedAtUtc], [InvitedByUserId]) VALUES (:u, SYSUTCDATETIME(), :hr)', [':u' => $this->invited, ':hr' => $this->hr]);
        $this->exec('INSERT INTO [onboarding].[TraineeProfiles] ([UserId]) VALUES (:u)', [':u' => $this->uninvited]);
        $this->exec(
            'INSERT INTO [onboarding].[TraineeProfiles] ([UserId], [InvitedAtUtc], [InvitedByUserId], [DepartedAtUtc], [DepartureRecordedByUserId]) VALUES (:u, DATEADD(DAY, -60, SYSUTCDATETIME()), :hr, DATEADD(DAY, -1, SYSUTCDATETIME()), :hr2)',
            [':u' => $this->leaver, ':hr' => $this->hr, ':hr2' => $this->hr],
        );

        $versions = [];
        foreach (['Synthetic reminder policy', 'Synthetic reminder policy B', 'Synthetic reminder policy C', 'Synthetic reminder policy D', 'Synthetic reminder policy E'] as $title) {
            $this->exec('INSERT INTO [onboarding].[Policies] ([Slug], [Title]) VALUES (:slug, :title)', [':slug' => 'syn-r-' . bin2hex(random_bytes(6)), ':title' => $title]);
            $policyId = (int) $this->db()->lastInsertId();
            $this->exec(
                "INSERT INTO [onboarding].[PolicyVersions] ([PolicyId], [VersionNumber], [ContentJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId]) VALUES (:p, 1, N'{\"blocks\":[]}', 1, SYSUTCDATETIME(), :u)",
                [':p' => $policyId, ':u' => $this->hr],
            );
            $versions[] = (int) $this->db()->lastInsertId();
        }

        $this->assign($this->invited, $versions[0], 'not_started', '2020-01-02');
        $this->assign($this->invited, $versions[1], 'fix_and_resubmit', null);
        $this->assign($this->invited, $versions[2], 'completed', null);
        $this->assign($this->invited, $versions[3], 'need_help', null);
        $this->assign($this->invited, $versions[4], 'not_started', null, true);
        $this->assign($this->uninvited, $versions[0], 'not_started', null);
        $this->assign($this->leaver, $versions[0], 'in_progress', null);
    }

    private function assign(int $userId, int $versionId, string $status, ?string $dueDate, bool $withdrawn = false): void
    {
        $completed = $status === 'completed' ? 'SYSUTCDATETIME()' : 'NULL';
        $withdrawal = $withdrawn ? 'SYSUTCDATETIME()' : 'NULL';
        $by = $withdrawn ? ':w' : 'NULL';
        $params = [':u' => $userId, ':v' => $versionId, ':s' => $status, ':a' => $this->hr, ':d' => $dueDate];
        if ($withdrawn) {
            $params[':w'] = $this->hr;
        }
        $this->exec(
            "INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [PolicyVersionId], [Status], [AllocatedByUserId], [DueDate], [CompletedAtUtc], [WithdrawnAtUtc], [WithdrawnByUserId])
             VALUES (:u, N'policy', :v, :s, :a, :d, {$completed}, {$withdrawal}, {$by})",
            $params,
        );
    }

    /**
     * @param list<int> $userIds
     * @return list<OutstandingReminderItem>
     */
    private function candidatesFor(array $userIds): array
    {
        $all = $this->reminders->listOutstanding(ReminderPolicy::remindedStatuses(), '2026-09-26', $this->now);

        return array_values(array_filter($all, static fn (OutstandingReminderItem $i) => in_array($i->userId, $userIds, true)));
    }

    /** @return array{string, ?string} */
    private function outcome(int $id): array
    {
        $statement = $this->db()->prepare('SELECT [Outcome], [FailureReason] FROM [onboarding].[NotificationLog] WHERE [NotificationLogId] = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertIsString($row['Outcome']);
        self::assertTrue($row['FailureReason'] === null || is_string($row['FailureReason']));

        return [$row['Outcome'], $row['FailureReason']];
    }

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db()->prepare($sql)->execute($params);
    }

    private function db(): PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }
}
