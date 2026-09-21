<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;

/**
 * Phase 2.4 — the schema's invariants, proved against the real database.
 *
 * `OnboardingSchemaInvariantsTest` executes; `OnboardingSchemaTest` reads the
 * DDL. They are deliberately both there. The DDL test runs everywhere and
 * catches a constraint somebody deleted from the script; this one catches a
 * constraint that is in the script and does not do what the script's author
 * thought. Only the second kind survives a code review.
 *
 * Everything here writes synthetic rows and removes them again, inside a
 * transaction that is always rolled back — so a run leaves the database exactly
 * as it found it, including on failure. That matters more than usual: the target
 * is `tc` on tricab-mel, which is shared.
 *
 * Skips when Azure SQL is unreachable. Locally that means the VPN is down or the
 * serverless database is resuming, neither of which is a test failure.
 */
#[Group('live-azure-sql')]
final class OnboardingSchemaInvariantsTest extends TestCase
{
    private ?PDO $pdo = null;

    /** A real UserId to hang synthetic rows off. Onboarding links by UserId only. */
    private int $userId;

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

            $users = $pdo->query('SELECT TOP 1 UserId FROM [identity].[Users] ORDER BY UserId');
            $userId = $users === false ? null : $users->fetchColumn();
        } catch (PDOException $e) {
            self::markTestSkipped('Azure SQL query failed: ' . $e->getMessage());
        }

        if (!is_numeric($userId)) {
            self::markTestSkipped('No users in identity.Users to attach synthetic onboarding rows to.');
        }

        $this->pdo = $pdo;
        $this->userId = (int) $userId;
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Always. These are synthetic rows in a shared database, and a test that
        // leaves them behind is worse than a test that does not run.
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
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);
    }

    /** @param array<string, mixed> $params */
    private function one(string $sql, array $params = []): mixed
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * A single value, as an int. The assertion is the point: a query that
     * returns no row gives `false`, and `(int) false` is `0` — which for a
     * COUNT test is indistinguishable from a real answer of zero, and would
     * make a broken query look like a passing assertion.
     *
     * @param array<string, mixed> $params
     */
    private function oneInt(string $sql, array $params = []): int
    {
        $value = $this->one($sql, $params);
        self::assertIsNumeric($value, 'Expected a numeric single value from: ' . $sql);

        return (int) $value;
    }

    /** @param array<string, mixed> $params */
    private function oneString(string $sql, array $params = []): string
    {
        $value = $this->one($sql, $params);
        self::assertTrue(
            is_string($value) || is_numeric($value),
            'Expected a string single value from: ' . $sql,
        );

        return (string) $value;
    }

    /**
     * A published form definition version, which most invariants need something
     * to hang off.
     */
    private function aFormVersion(string $slug): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug],[Title],[AccessGroup])
             VALUES (:slug, N'Synthetic', N'hr')",
            [':slug' => $slug],
        );
        $definitionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitionVersions]
                ([FormDefinitionId],[VersionNumber],[SchemaJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:d, 1, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':d' => $definitionId, ':u' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function anAssignment(int $formVersionId): int
    {
        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId])
             VALUES (:u, N'form', :v, :u2)",
            [':u' => $this->userId, ':v' => $formVersionId, ':u2' => $this->userId],
        );

        return (int) $this->db()->lastInsertId();
    }

    private function aSubmission(int $assignmentId, int $formVersionId, int $revision = 1): string
    {
        $this->exec(
            "INSERT INTO [onboarding].[FormSubmissions]
                ([AssignmentId],[UserId],[FormDefinitionVersionId],[RevisionNumber],[SubmittedDataJson])
             VALUES (:a, :u, :v, :r, N'{\"a\":1}')",
            [':a' => $assignmentId, ':u' => $this->userId, ':v' => $formVersionId, ':r' => $revision],
        );

        return $this->oneString(
            'SELECT [PublicId] FROM [onboarding].[FormSubmissions] WHERE [FormSubmissionId] = :id',
            [':id' => (int) $this->db()->lastInsertId()],
        );
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Assert the database refused a write, AND that it refused it for the
     * reason the test is about.
     *
     * `expectException(PDOException::class)` alone is a weak assertion here: a
     * mistyped column name also throws one, so a test written to prove a trigger
     * fires would pass just as happily against a table that does not exist. Each
     * caller names the constraint, index or trigger message it expects, so the
     * test fails if the write is refused by the wrong thing.
     */
    private function assertRefusedBy(string $expectedInMessage, callable $write): void
    {
        try {
            $write();
        } catch (PDOException $e) {
            self::assertStringContainsString(
                $expectedInMessage,
                $e->getMessage(),
                sprintf('The write was refused, but not by %s.', $expectedInMessage),
            );

            return;
        }

        self::fail(sprintf('The database accepted a write that %s should have refused.', $expectedInMessage));
    }

    // ---------------------------------------------------------------- A5

    /**
     * Gap A5, stated as "an update to a submitted row FAILS rather than silently
     * succeeding". A repository convention cannot promise that; the trigger can.
     */
    public function test_a_submitted_form_cannot_be_rewritten(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-immutable'));
        $assignmentId = $this->anAssignment($versionId);
        $this->aSubmission($assignmentId, $versionId);

        $this->assertRefusedBy('onboarding.FormSubmissions is immutable', fn () => $this->exec(
            "UPDATE [onboarding].[FormSubmissions] SET [SubmittedDataJson] = N'{\"a\":2}'
             WHERE [AssignmentId] = :a",
            [':a' => $assignmentId],
        ));
    }

    public function test_a_submission_cannot_be_deleted(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-nodelete'));
        $assignmentId = $this->anAssignment($versionId);
        $this->aSubmission($assignmentId, $versionId);

        $this->assertRefusedBy('A submission is never deleted', fn () => $this->exec(
            'DELETE FROM [onboarding].[FormSubmissions] WHERE [AssignmentId] = :a',
            [':a' => $assignmentId],
        ));
    }

    /**
     * The one permitted update: marking a revision superseded when its
     * replacement arrives. Bookkeeping, not content — and the reason this
     * trigger is AFTER rather than INSTEAD OF, so its immutability does not
     * depend on the RECURSIVE_TRIGGERS database option.
     */
    public function test_a_revision_may_still_be_marked_superseded(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-supersede'));
        $assignmentId = $this->anAssignment($versionId);
        $this->aSubmission($assignmentId, $versionId, 1);
        $this->aSubmission($assignmentId, $versionId, 2);

        $this->exec(
            'UPDATE [onboarding].[FormSubmissions] SET [SupersededByRevision] = 2
             WHERE [AssignmentId] = :a AND [RevisionNumber] = 1',
            [':a' => $assignmentId],
        );

        self::assertSame(2, $this->oneInt(
            'SELECT [SupersededByRevision] FROM [onboarding].[FormSubmissions]
             WHERE [AssignmentId] = :a AND [RevisionNumber] = 1',
            [':a' => $assignmentId],
        ));
    }

    /** A correction is a new revision, and two revisions cannot share a number. */
    public function test_a_revision_number_is_unique_per_assignment(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-revision'));
        $assignmentId = $this->anAssignment($versionId);
        $this->aSubmission($assignmentId, $versionId, 1);

        $this->assertRefusedBy(
            'UQ_onboarding_FormSubmissions_Revision',
            fn () => $this->aSubmission($assignmentId, $versionId, 1),
        );
    }

    // ---------------------------------------------------------------- A6

    /**
     * Gap A6: superseding a policy must not move an acknowledgement. The
     * acknowledgement points at the version, so this is structural — the test
     * proves the structure holds when the policy actually moves on.
     */
    public function test_superseding_a_policy_leaves_its_acknowledgement_on_the_old_version(): void
    {
        $slug = $this->uniqueSlug('syn-policy');
        $this->exec(
            "INSERT INTO [onboarding].[Policies] ([Slug],[Title]) VALUES (:s, N'Synthetic')",
            [':s' => $slug],
        );
        $policyId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[PolicyVersions]
                ([PolicyId],[VersionNumber],[ContentJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:p, 1, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':p' => $policyId, ':u' => $this->userId],
        );
        $v1 = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[PolicyVersionId],[AllocatedByUserId])
             VALUES (:u, N'policy', :v, :u2)",
            [':u' => $this->userId, ':v' => $v1, ':u2' => $this->userId],
        );
        $assignmentId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[PolicyAcknowledgements]
                ([AssignmentId],[UserId],[PolicyVersionId],[DeclarationText])
             VALUES (:a, :u, :v, N'I agree')",
            [':a' => $assignmentId, ':u' => $this->userId, ':v' => $v1],
        );

        // Supersede v1 and publish v2.
        $this->exec(
            'UPDATE [onboarding].[PolicyVersions] SET [SupersededAtUtc] = SYSUTCDATETIME() WHERE [PolicyVersionId] = :v',
            [':v' => $v1],
        );
        $this->exec(
            "INSERT INTO [onboarding].[PolicyVersions]
                ([PolicyId],[VersionNumber],[ContentJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:p, 2, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':p' => $policyId, ':u' => $this->userId],
        );

        self::assertSame($v1, $this->oneInt(
            'SELECT [PolicyVersionId] FROM [onboarding].[PolicyAcknowledgements] WHERE [AssignmentId] = :a',
            [':a' => $assignmentId],
        ));
    }

    public function test_an_acknowledgement_cannot_be_altered_or_removed(): void
    {
        $slug = $this->uniqueSlug('syn-ack');
        $this->exec("INSERT INTO [onboarding].[Policies] ([Slug],[Title]) VALUES (:s, N'Synthetic')", [':s' => $slug]);
        $policyId = (int) $this->db()->lastInsertId();
        $this->exec(
            "INSERT INTO [onboarding].[PolicyVersions]
                ([PolicyId],[VersionNumber],[ContentJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:p, 1, N'{}', 1, SYSUTCDATETIME(), :u)",
            [':p' => $policyId, ':u' => $this->userId],
        );
        $versionId = (int) $this->db()->lastInsertId();
        $this->exec(
            "INSERT INTO [onboarding].[Assignments] ([UserId],[ItemType],[PolicyVersionId],[AllocatedByUserId])
             VALUES (:u, N'policy', :v, :u2)",
            [':u' => $this->userId, ':v' => $versionId, ':u2' => $this->userId],
        );
        $assignmentId = (int) $this->db()->lastInsertId();
        $this->exec(
            "INSERT INTO [onboarding].[PolicyAcknowledgements]
                ([AssignmentId],[UserId],[PolicyVersionId],[DeclarationText])
             VALUES (:a, :u, :v, N'I agree')",
            [':a' => $assignmentId, ':u' => $this->userId, ':v' => $versionId],
        );

        $this->assertRefusedBy('PolicyAcknowledgements is append-only', fn () => $this->exec(
            "UPDATE [onboarding].[PolicyAcknowledgements] SET [DeclarationText] = N'I do not'
             WHERE [AssignmentId] = :a",
            [':a' => $assignmentId],
        ));
    }

    // ---------------------------------------------------------------- A7

    /**
     * Gap A7: only one version of a named form is allocatable at a time. The
     * filtered unique index is what stops two live versions of the same form
     * reaching new starters.
     */
    public function test_a_form_cannot_have_two_live_versions(): void
    {
        $slug = $this->uniqueSlug('syn-onelive');
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug],[Title],[AccessGroup]) VALUES (:s, N'Synthetic', N'hr')",
            [':s' => $slug],
        );
        $definitionId = (int) $this->db()->lastInsertId();

        $publish = "INSERT INTO [onboarding].[FormDefinitionVersions]
                ([FormDefinitionId],[VersionNumber],[SchemaJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:d, :n, N'{}', 1, SYSUTCDATETIME(), :u)";

        $this->exec($publish, [':d' => $definitionId, ':n' => 1, ':u' => $this->userId]);

        $this->assertRefusedBy(
            'UX_onboarding_FormDefinitionVersions_OneLive',
            fn () => $this->exec($publish, [':d' => $definitionId, ':n' => 2, ':u' => $this->userId]),
        );
    }

    /** Retiring the first makes room for the second — that is the whole point. */
    public function test_retiring_a_version_makes_room_for_the_next(): void
    {
        $slug = $this->uniqueSlug('syn-retire');
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug],[Title],[AccessGroup]) VALUES (:s, N'Synthetic', N'hr')",
            [':s' => $slug],
        );
        $definitionId = (int) $this->db()->lastInsertId();
        $publish = "INSERT INTO [onboarding].[FormDefinitionVersions]
                ([FormDefinitionId],[VersionNumber],[SchemaJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:d, :n, N'{}', 1, SYSUTCDATETIME(), :u)";

        $this->exec($publish, [':d' => $definitionId, ':n' => 1, ':u' => $this->userId]);
        $this->exec(
            'UPDATE [onboarding].[FormDefinitionVersions] SET [RetiredAtUtc] = SYSUTCDATETIME()
             WHERE [FormDefinitionId] = :d',
            [':d' => $definitionId],
        );
        $this->exec($publish, [':d' => $definitionId, ':n' => 2, ':u' => $this->userId]);

        self::assertSame(1, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[FormDefinitionVersions]
             WHERE [FormDefinitionId] = :d AND [IsPublished] = 1 AND [RetiredAtUtc] IS NULL',
            [':d' => $definitionId],
        ));
    }

    // ---------------------------------------------------------------- A8

    /** Gap A8: the reminder job cannot double-send, because the database refuses. */
    public function test_a_notification_cannot_be_sent_twice_in_one_day(): void
    {
        $type = $this->uniqueSlug('syn-remind');
        $insert = "INSERT INTO [onboarding].[NotificationLog]
                ([UserId],[NotificationType],[SubjectType],[SubjectPublicId],[SentOn],[Outcome])
             VALUES (:u, :t, N'assignment', NULL, '2026-09-21', N'sent')";

        $this->exec($insert, [':u' => $this->userId, ':t' => $type]);

        $this->assertRefusedBy(
            'UX_onboarding_NotificationLog_OncePerDay',
            fn () => $this->exec($insert, [':u' => $this->userId, ':t' => $type]),
        );
    }

    // ---------------------------------------------------------------- A9

    /**
     * Gap A9 and work order invariant 8. `explicit` is the only allocation
     * source the column admits, so granting by cohort or role is a migration
     * somebody has to write and defend rather than a line of application code.
     */
    public function test_allocation_cannot_claim_any_source_but_explicit(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-source'));

        $this->assertRefusedBy('CK_onboarding_Assignments_AllocationSource', fn () => $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId],[AllocationSource])
             VALUES (:u, N'form', :v, :u2, N'cohort')",
            [':u' => $this->userId, ':v' => $versionId, ':u2' => $this->userId],
        ));
    }

    /** The same item cannot be live-allocated to one person twice. */
    public function test_one_live_allocation_of_an_item_per_person(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-dupe'));
        $this->anAssignment($versionId);

        $this->assertRefusedBy(
            'UX_onboarding_Assignments_LiveForm',
            fn () => $this->anAssignment($versionId),
        );
    }

    /** Withdrawing frees it, because the uniqueness is filtered. Phase 2.3. */
    public function test_withdrawing_allows_the_item_to_be_allocated_again(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-realloc'));
        $first = $this->anAssignment($versionId);

        $this->exec(
            'UPDATE [onboarding].[Assignments]
             SET [WithdrawnAtUtc] = SYSUTCDATETIME(), [WithdrawnByUserId] = :u
             WHERE [AssignmentId] = :a',
            [':a' => $first, ':u' => $this->userId],
        );

        $second = $this->anAssignment($versionId);

        self::assertNotSame($first, $second);
        self::assertSame(2, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[Assignments] WHERE [FormDefinitionVersionId] = :v',
            [':v' => $versionId],
        ));
    }

    /** An assignment names exactly one item, and it matches its declared type. */
    public function test_an_assignment_cannot_name_two_items_or_the_wrong_one(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-polymorph'));

        $this->assertRefusedBy('CK_onboarding_Assignments_ItemMatchesType', fn () => $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId])
             VALUES (:u, N'policy', :v, :u2)",
            [':u' => $this->userId, ':v' => $versionId, ':u2' => $this->userId],
        ));
    }

    // ------------------------------------------------------ the leaver window

    /**
     * The fourteen days, computed by the database rather than by PHP. This is
     * the half of the drift guard that `OnboardingSchemaTest` cannot reach: it
     * checks the script says 14, and this checks the column does 14.
     */
    public function test_the_leaver_window_is_fourteen_days_after_departure(): void
    {
        $this->exec(
            "INSERT INTO [onboarding].[TraineeProfiles] ([UserId],[DepartedAtUtc],[DepartureRecordedByUserId])
             VALUES (:u, '2026-09-01T09:00:00', :r)",
            [':u' => $this->userId, ':r' => $this->userId],
        );

        self::assertSame('2026-09-15 09:00:00', substr($this->oneString(
            'SELECT [AccessEndsAtUtc] FROM [onboarding].[TraineeProfiles] WHERE [UserId] = :u',
            [':u' => $this->userId],
        ), 0, 19));
    }

    /** A departure nobody recorded is a departure nobody can be asked about. */
    public function test_a_departure_must_name_who_recorded_it(): void
    {
        $this->assertRefusedBy('CK_onboarding_TraineeProfiles_Departure', fn () => $this->exec(
            "INSERT INTO [onboarding].[TraineeProfiles] ([UserId],[DepartedAtUtc]) VALUES (:u, SYSUTCDATETIME())",
            [':u' => $this->userId],
        ));
    }

    // ------------------------------------------------------------- retention

    /**
     * Every class keeps its records until somebody states a period, and a period
     * cannot be set without naming who decided it. These are employee records;
     * Fair Work Act 2009 (Cth) s.535 requires seven years of them.
     */
    public function test_nothing_is_scheduled_for_deletion(): void
    {
        self::assertSame(0, $this->oneInt(
            'SELECT COUNT(*) FROM [onboarding].[RetentionClasses] WHERE [RetentionDays] IS NOT NULL',
        ));
        self::assertSame(6, $this->oneInt('SELECT COUNT(*) FROM [onboarding].[RetentionClasses]'));
    }

    public function test_a_retention_period_cannot_be_set_anonymously(): void
    {
        $this->assertRefusedBy('CK_onboarding_RetentionClasses_Decided', fn () => $this->exec(
            'UPDATE [onboarding].[RetentionClasses] SET [RetentionDays] = 14 WHERE [RetentionClassId] = 3',
        ));
    }

    // --------------------------------------------------- the classification split

    /** A submitted field is ordinary, payroll or restricted — never content or workflow. */
    public function test_a_field_cannot_be_classified_as_content_or_workflow(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-classify'));

        $this->assertRefusedBy('CK_onboarding_hr_FieldClassifications_Class', fn () => $this->exec(
            "INSERT INTO [onboarding_hr].[FieldClassifications]
                ([FormDefinitionVersionId],[FieldPath],[RetentionClassId],[ClassifiedByUserId])
             VALUES (:v, N'surname', 1, :u)",
            [':v' => $versionId, ':u' => $this->userId],
        ));
    }

    public function test_a_field_may_be_classified_ordinary(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-ordinary'));

        $this->exec(
            "INSERT INTO [onboarding_hr].[FieldClassifications]
                ([FormDefinitionVersionId],[FieldPath],[RetentionClassId],[ClassifiedByUserId])
             VALUES (:v, N'surname', 2, :u)",
            [':v' => $versionId, ':u' => $this->userId],
        );

        self::assertSame(2, $this->oneInt(
            'SELECT [RetentionClassId] FROM [onboarding_hr].[FieldClassifications] WHERE [FormDefinitionVersionId] = :v',
            [':v' => $versionId],
        ));
    }

    /** The split half of a submission is evidence exactly as the submission is. */
    public function test_the_restricted_half_of_a_submission_is_append_only(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-split'));
        $assignmentId = $this->anAssignment($versionId);
        $submissionPublicId = $this->aSubmission($assignmentId, $versionId);

        $this->exec(
            "INSERT INTO [onboarding_hr].[RestrictedSubmissionData]
                ([FormSubmissionPublicId],[UserId],[RetentionClassId],[PayloadJson],[FieldCount])
             VALUES (:p, :u, 3, N'{\"bsb\":\"063-000\"}', 1)",
            [':p' => $submissionPublicId, ':u' => $this->userId],
        );

        $this->assertRefusedBy('RestrictedSubmissionData is append-only', fn () => $this->exec(
            "UPDATE [onboarding_hr].[RestrictedSubmissionData] SET [PayloadJson] = N'{}'
             WHERE [FormSubmissionPublicId] = :p",
            [':p' => $submissionPublicId],
        ));
    }

    // ------------------------------------------------------------------ audit

    /** The audit trail is append-only, which is the only property that makes it one. */
    public function test_a_status_event_cannot_be_rewritten(): void
    {
        $assignmentPublicId = $this->oneString('SELECT CONVERT(NVARCHAR(36), NEWID())');

        $this->exec(
            "INSERT INTO [audit].[OnboardingStatusEvents]
                ([AssignmentPublicId],[SubjectUserId],[ActorUserId],[ActorType],[FromStatus],[ToStatus])
             VALUES (:p, :u, :a, N'reviewer', N'submitted', N'completed')",
            [':p' => $assignmentPublicId, ':u' => $this->userId, ':a' => $this->userId],
        );

        $this->assertRefusedBy('OnboardingStatusEvents is append-only', fn () => $this->exec(
            "UPDATE [audit].[OnboardingStatusEvents] SET [ToStatus] = N'need_help'
             WHERE [AssignmentPublicId] = :p",
            [':p' => $assignmentPublicId],
        ));
    }

    /** A human action recorded with no actor is an unattributable one. */
    public function test_a_non_system_event_must_name_its_actor(): void
    {
        $this->assertRefusedBy('CK_audit_OnboardingStatusEvents_Attribution', fn () => $this->exec(
            "INSERT INTO [audit].[OnboardingStatusEvents]
                ([AssignmentPublicId],[SubjectUserId],[ActorUserId],[ActorType],[ToStatus])
             VALUES (CONVERT(NVARCHAR(36), NEWID()), :u, NULL, N'reviewer', N'completed')",
            [':u' => $this->userId],
        ));
    }

    /** The system acts for no person, and that is a legitimate record. */
    public function test_the_system_may_act_without_an_actor(): void
    {
        $assignmentPublicId = $this->oneString('SELECT CONVERT(NVARCHAR(36), NEWID())');

        $this->exec(
            "INSERT INTO [audit].[OnboardingStatusEvents]
                ([AssignmentPublicId],[SubjectUserId],[ActorUserId],[ActorType],[ToStatus])
             VALUES (:p, :u, NULL, N'system', N'in_progress')",
            [':p' => $assignmentPublicId, ':u' => $this->userId],
        );

        self::assertSame(1, $this->oneInt(
            'SELECT COUNT(*) FROM [audit].[OnboardingStatusEvents] WHERE [AssignmentPublicId] = :p',
            [':p' => $assignmentPublicId],
        ));
    }

    // ------------------------------------------------------------- statuses

    /** The database's copy of AssignmentStatus refuses anything outside it. */
    public function test_an_invented_status_is_refused(): void
    {
        $versionId = $this->aFormVersion($this->uniqueSlug('syn-status'));

        $this->assertRefusedBy('CK_onboarding_Assignments_Status', fn () => $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId],[Status])
             VALUES (:u, N'form', :v, :u2, N'nearly_done')",
            [':u' => $this->userId, ':v' => $versionId, ':u2' => $this->userId],
        ));
    }
}
