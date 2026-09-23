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
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAssignmentLocator;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingFormRepository;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingReviewRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Application\Service\DecideReviewService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 4.3 against `tricab-mel`, inside a transaction that is always rolled
 * back. Synthetic content only: the profile, form, classifications, submission
 * and assignment are all created here and gone when the test ends.
 *
 * Uses two people with no induction record, as the 4.2 live tests do, so the
 * demo data seeded on the shared database is never touched.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingReviewRepositoryTest extends TestCase
{
    private const SYNTHETIC_NAME = 'Synthetic Reviewee';
    private const SYNTHETIC_ACCOUNT = '000-SYN-4242';

    private ?PDO $pdo = null;
    private AzureSqlOnboardingReviewRepository $reviews;
    private AzureSqlOnboardingFormRepository $forms;
    private DecideReviewService $service;
    private int $subject;
    private int $reviewer;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[audit].[OnboardingAdminEvents]', N'U') IS NULL OR OBJECT_ID(N'[onboarding_hr].[FieldClassifications]', N'U') IS NULL THEN 0 ELSE 1 END");
            if ($applied === false || (int) ($applied->fetchColumn() ?: 0) !== 1) {
                self::markTestSkipped('The onboarding schemas and the HR audit table are not all applied to this database.');
            }
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
        $this->reviewer = (int) $ids[1];

        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test'));
        $this->reviews = new AzureSqlOnboardingReviewRepository($connection);
        $this->forms = new AzureSqlOnboardingFormRepository($connection, new AzureSqlOnboardingAssignmentLocator($connection));
        $this->service = new DecideReviewService($this->reviews, new AzureSqlOnboardingAdminAuditLog($connection));

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo = null;
    }

    public function test_a_submitted_form_is_queued_and_opens_with_restricted_fields_withheld(): void
    {
        $assignment = $this->submittedForm();

        $queued = [];
        for ($page = 1; $page <= 20; ++$page) {
            $result = $this->reviews->listQueue($page, 100);
            $queued = [...$queued, ...array_map(static fn (ReviewQueueEntry $e) => $e->assignmentPublicId, $result['items'])];
            if (count($result['items']) < 100) {
                break;
            }
        }
        self::assertContains($assignment, $queued);

        $detail = $this->reviews->reviewDetail($assignment);
        self::assertNotNull($detail);
        self::assertSame('submitted', $detail->status()->toString());

        $fields = array_column($detail->formSubmission['fields'] ?? [], null, 'path');
        self::assertSame(self::SYNTHETIC_NAME, $fields['preferredName']['value']);
        self::assertTrue($fields['bank.accountNumber']['withheld']);
        self::assertNull($fields['bank.accountNumber']['value']);
        self::assertTrue($detail->formSubmission['hasRestrictedData'] ?? false);
        self::assertStringNotContainsString(self::SYNTHETIC_ACCOUNT, json_encode($detail->toArray($this->reviewer), JSON_THROW_ON_ERROR));
    }

    public function test_sending_back_writes_the_status_the_reviewer_and_both_audit_rows_and_the_person_sees_the_reason(): void
    {
        $assignment = $this->submittedForm();
        $detail = $this->reviews->reviewDetail($assignment);
        self::assertNotNull($detail);

        $after = $this->service->decide(
            $this->actor($this->reviewer),
            $assignment,
            RowVersionToken::fromBase64($detail->rowVersion),
            AssignmentStatus::fixAndResubmit(),
            'Synthetic: the start date is before the offer date.',
        );

        self::assertSame('fix_and_resubmit', $after->status()->toString());
        self::assertSame([], $after->toArray($this->reviewer)['outcomes']);
        self::assertSame((string) $this->reviewer, $this->text(
            'SELECT [ReviewedByUserId] FROM [onboarding].[Assignments] WHERE [PublicId] = CONVERT(UNIQUEIDENTIFIER, :p) AND [ReviewedAtUtc] IS NOT NULL',
            [':p' => $assignment],
        ));

        $status = $this->row(
            'SELECT [ActorType], [ActorUserId], [FromStatus], [ToStatus], [Note] FROM [audit].[OnboardingStatusEvents]
             WHERE [AssignmentPublicId] = CONVERT(UNIQUEIDENTIFIER, :p) ORDER BY [OnboardingStatusEventId] DESC',
            [':p' => $assignment],
        );
        self::assertSame(['reviewer', (string) $this->reviewer, 'submitted', 'fix_and_resubmit', 'Synthetic: the start date is before the offer date.'], array_map(self::str(...), array_values($status)));

        $admin = $this->row(
            'SELECT [EventType], [Outcome], [PermissionCode], [EventSummary], [DetailsJson] FROM [audit].[OnboardingAdminEvents]
             WHERE [SubjectUserId] = :u ORDER BY [OnboardingAdminEventId] DESC',
            [':u' => $this->subject],
        );
        self::assertSame('review.decided', $admin['EventType']);
        self::assertSame('succeeded', $admin['Outcome']);
        self::assertSame('ONBOARDING.REVIEW', $admin['PermissionCode']);
        self::assertStringNotContainsString('offer date', self::str($admin['EventSummary']) . self::str($admin['DetailsJson']), 'the note is not copied into the HR audit table');
        self::assertStringContainsString('"toStatus":"fix_and_resubmit"', self::str($admin['DetailsJson']));

        $mine = $this->forms->findForm($this->subject, $assignment);
        self::assertNotNull($mine);
        self::assertSame('Synthetic: the start date is before the offer date.', $mine->reviewerNote);
    }

    public function test_accepting_completes_it_and_the_person_is_no_longer_shown_a_note(): void
    {
        $assignment = $this->submittedForm();
        $detail = $this->reviews->reviewDetail($assignment);
        self::assertNotNull($detail);

        $this->service->decide($this->actor($this->reviewer), $assignment, RowVersionToken::fromBase64($detail->rowVersion), AssignmentStatus::completed(), null);

        self::assertSame(1, $this->number(
            "SELECT COUNT(*) FROM [onboarding].[Assignments] WHERE [PublicId] = CONVERT(UNIQUEIDENTIFIER, :p) AND [Status] = N'completed' AND [CompletedAtUtc] IS NOT NULL",
            [':p' => $assignment],
        ));
        self::assertNull($this->forms->findForm($this->subject, $assignment)?->reviewerNote);
    }

    public function test_a_stale_token_writes_nothing(): void
    {
        $assignment = $this->submittedForm();
        $detail = $this->reviews->reviewDetail($assignment);
        self::assertNotNull($detail);
        $before = $this->number('SELECT COUNT(*) FROM [audit].[OnboardingStatusEvents] WHERE [AssignmentPublicId] = CONVERT(UNIQUEIDENTIFIER, :p)', [':p' => $assignment]);

        // Something else touches the row between the reviewer's read and write.
        $this->exec("UPDATE [onboarding].[Assignments] SET [DueDate] = '2026-12-31' WHERE [PublicId] = CONVERT(UNIQUEIDENTIFIER, :p)", [':p' => $assignment]);

        try {
            $this->service->decide($this->actor($this->reviewer), $assignment, RowVersionToken::fromBase64($detail->rowVersion), AssignmentStatus::completed(), null);
            self::fail('A stale token was accepted.');
        } catch (OnboardingWriteConflict) {
        }

        self::assertSame('submitted', $this->reviews->findReviewItem($assignment)?->status->toString());
        self::assertSame($before, $this->number('SELECT COUNT(*) FROM [audit].[OnboardingStatusEvents] WHERE [AssignmentPublicId] = CONVERT(UNIQUEIDENTIFIER, :p)', [':p' => $assignment]));
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM [audit].[OnboardingAdminEvents] WHERE [SubjectUserId] = :u AND [EventType] = N'review.decided'", [':u' => $this->subject]));
    }

    public function test_reviewing_ones_own_form_is_refused_and_the_refusal_is_on_record(): void
    {
        $assignment = $this->submittedForm();
        $detail = $this->reviews->reviewDetail($assignment);
        self::assertNotNull($detail);

        try {
            $this->service->decide($this->actor($this->subject), $assignment, RowVersionToken::fromBase64($detail->rowVersion), AssignmentStatus::completed(), null);
            self::fail('Reviewed their own form.');
        } catch (SelfReviewNotPermitted) {
        }

        self::assertSame('submitted', $this->reviews->findReviewItem($assignment)?->status->toString());
        $admin = $this->row(
            'SELECT [EventType], [Outcome], [ActorUserId], [DetailsJson] FROM [audit].[OnboardingAdminEvents] WHERE [SubjectUserId] = :u ORDER BY [OnboardingAdminEventId] DESC',
            [':u' => $this->subject],
        );
        self::assertSame(['review.refused', 'refused', (string) $this->subject], [self::str($admin['EventType']), self::str($admin['Outcome']), self::str($admin['ActorUserId'])]);
        self::assertStringContainsString('"reason":"self_review"', self::str($admin['DetailsJson']));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A person with one submitted form: one ordinary field and one payroll
     * field, classified, with the payroll value placed in the ordinary half on
     * purpose so the projection's own check is what keeps it off the screen.
     */
    private function submittedForm(): string
    {
        $this->exec('INSERT INTO [onboarding].[TraineeProfiles] ([UserId]) VALUES (:u)', [':u' => $this->subject]);

        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug], [Title], [AccessGroup]) VALUES (:slug, N'Synthetic starter details', N'payroll')",
            [':slug' => 'syn-review-' . bin2hex(random_bytes(6))],
        );
        $definitionId = (int) $this->db()->lastInsertId();
        $schema = '{"fields":[{"path":"preferredName","label":"Preferred name","type":"text"},{"path":"bank.accountNumber","label":"Account number","type":"text"}]}';
        $this->exec(
            'INSERT INTO [onboarding].[FormDefinitionVersions] ([FormDefinitionId], [VersionNumber], [SchemaJson], [IsPublished], [PublishedAtUtc], [PublishedByUserId])
             VALUES (:d, 1, :schema, 1, SYSUTCDATETIME(), :u)',
            [':d' => $definitionId, ':schema' => $schema, ':u' => $this->reviewer],
        );
        $versionId = (int) $this->db()->lastInsertId();
        foreach (['preferredName' => DataClass::ORDINARY_HR, 'bank.accountNumber' => DataClass::PAYROLL] as $path => $class) {
            $this->exec(
                'INSERT INTO [onboarding_hr].[FieldClassifications] ([FormDefinitionVersionId], [FieldPath], [RetentionClassId], [ClassifiedByUserId]) VALUES (:v, :p, :c, :u)',
                [':v' => $versionId, ':p' => $path, ':c' => $class, ':u' => $this->reviewer],
            );
        }

        $this->exec(
            "INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [FormDefinitionVersionId], [Status], [AllocatedByUserId], [StartedAtUtc], [SubmittedAtUtc])
             VALUES (:u, N'form', :v, N'submitted', :a, SYSUTCDATETIME(), SYSUTCDATETIME())",
            [':u' => $this->subject, ':v' => $versionId, ':a' => $this->reviewer],
        );
        $assignmentId = (int) $this->db()->lastInsertId();

        $this->exec(
            'INSERT INTO [onboarding].[FormSubmissions] ([AssignmentId], [UserId], [FormDefinitionVersionId], [RevisionNumber], [SubmittedDataJson], [HasRestrictedData])
             VALUES (:a, :u, :v, 1, :data, 1)',
            [
                ':a' => $assignmentId,
                ':u' => $this->subject,
                ':v' => $versionId,
                ':data' => json_encode(['preferredName' => self::SYNTHETIC_NAME, 'bank.accountNumber' => self::SYNTHETIC_ACCOUNT], JSON_THROW_ON_ERROR),
            ],
        );

        return strtolower($this->text('SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[Assignments] WHERE [AssignmentId] = :a', [':a' => $assignmentId]));
    }

    private function actor(int $userId): ActorContext
    {
        return new ActorContext($userId, 'actor-public-id', 'actor@example.test', 'test-correlation');
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

    /** @param array<string, mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db()->prepare($sql)->execute($params);
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
