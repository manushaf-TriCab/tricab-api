<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * The Phase 2.2 schema, checked against the decisions it is supposed to encode.
 *
 * Nothing here needs a database. These are the properties that would otherwise
 * be true only until somebody edited one of two files — a constant in PHP and a
 * number in DDL, an enum and a CHECK constraint, an invariant in a work order
 * and an absent foreign key. Each one is cheap to get wrong and expensive to
 * notice, because the symptom appears in production data rather than in a build.
 *
 * Follows the precedent set by ProductCodeSearchSqlSchemaTest: read the
 * committed DDL, assert against it, and run everywhere with no database.
 */
final class OnboardingSchemaTest extends TestCase
{
    private const WORKFLOW_SCHEMA = 'database/scripts/development/create-onboarding-schema.sql';
    private const HR_SCHEMA = 'database/scripts/development/create-onboarding-hr-schema.sql';

    private function sql(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);

        self::assertIsString($contents, sprintf('Could not read %s', $relativePath));

        return $contents;
    }

    // -----------------------------------------------------------------
    // The leaver policy
    // -----------------------------------------------------------------

    /**
     * The grace window is stated twice — once in LeaverPolicy so the domain can
     * reason about it, and once in the persisted computed column so "whose
     * window has closed" is an index seek. Two statements of one rule drift, and
     * this particular drift is a silent authorization change: a leaver keeps or
     * loses access for a length of time nobody chose, and no ordinary test
     * notices because both halves are internally consistent.
     */
    public function test_the_computed_access_window_matches_the_leaver_policy(): void
    {
        $sql = $this->sql(self::WORKFLOW_SCHEMA);

        self::assertMatchesRegularExpression(
            '/\[AccessEndsAtUtc\]\s+AS\s+DATEADD\(DAY,\s*' . LeaverPolicy::ACCESS_WINDOW_DAYS . ',\s*\[DepartedAtUtc\]\)\s+PERSISTED/i',
            $sql,
            sprintf(
                'onboarding.TraineeProfiles.AccessEndsAtUtc must add exactly %d days, to match LeaverPolicy::ACCESS_WINDOW_DAYS.',
                LeaverPolicy::ACCESS_WINDOW_DAYS,
            ),
        );
    }

    /**
     * A departure recorded by nobody is a departure nobody can be asked about.
     */
    public function test_a_departure_must_name_who_recorded_it(): void
    {
        self::assertStringContainsString(
            'CK_onboarding_TraineeProfiles_Departure',
            $this->sql(self::WORKFLOW_SCHEMA),
        );
    }

    // -----------------------------------------------------------------
    // Retention
    // -----------------------------------------------------------------

    /**
     * The fourteen days are a READ-ACCESS window. They are not a deletion
     * schedule, and the two must never be conflated: these are employee records,
     * which the Fair Work Act 2009 (Cth) s.535 requires an employer to keep for
     * seven years.
     *
     * So every seeded retention class must have a NULL period — "no figure
     * decided; keep" — and the day somebody seeds a number instead, this test
     * fails and makes them say so out loud.
     */
    public function test_no_retention_class_is_seeded_with_a_deletion_period(): void
    {
        $sql = $this->sql(self::WORKFLOW_SCHEMA);

        self::assertMatchesRegularExpression(
            '/INSERT \(\[RetentionClassId\], \[ClassCode\], \[ClassName\], \[Description\], \[RetentionDays\]\)\s*VALUES \(source\.\[RetentionClassId\], source\.\[ClassCode\], source\.\[ClassName\], source\.\[Description\], NULL\)/',
            $sql,
            'Retention classes must seed RetentionDays as NULL. A deletion period is an owner decision with advice behind it, not a default.',
        );
    }

    public function test_a_retention_period_cannot_be_set_without_somebody_behind_it(): void
    {
        self::assertStringContainsString(
            'CK_onboarding_RetentionClasses_Decided',
            $this->sql(self::WORKFLOW_SCHEMA),
        );
    }

    // -----------------------------------------------------------------
    // Allocation is explicit — work order invariant 8, gap A9
    // -----------------------------------------------------------------

    /**
     * Nothing is granted by cohort, role, title or department. The CHECK admits
     * one value, so adding a second is a migration somebody has to write and
     * defend rather than a line of PHP.
     */
    public function test_allocation_source_admits_only_explicit(): void
    {
        self::assertMatchesRegularExpression(
            "/CONSTRAINT \[CK_onboarding_Assignments_AllocationSource\]\s*\n?\s*CHECK \(\[AllocationSource\] = N'explicit'\)/",
            $this->sql(self::WORKFLOW_SCHEMA),
        );
    }

    /**
     * TriCab does not use cohorts (owner, 21 Sep 2026), so there is no table for
     * them — which also closes open question 4 without anybody having to settle
     * it. The source's schema comments suggest changing a trainee's cohort
     * re-derives their assignments, the exact opposite of invariant 8; a concept
     * that is absent cannot be wired up that way by a later change that seemed
     * reasonable at the time.
     */
    public function test_cohorts_do_not_exist(): void
    {
        $sql = $this->withoutComments($this->sql(self::WORKFLOW_SCHEMA));

        self::assertStringNotContainsString('[Cohorts]', $sql);
        self::assertStringNotContainsString('CohortId', $sql);
    }

    // -----------------------------------------------------------------
    // The status vocabulary
    // -----------------------------------------------------------------

    /**
     * The CHECK constraints are the database's copy of AssignmentStatus. A
     * status added to the domain and not here is rejected at write time with a
     * constraint violation nobody reading the PHP would predict.
     */
    public function test_every_status_check_matches_the_domain_vocabulary(): void
    {
        $sql = $this->sql(self::WORKFLOW_SCHEMA);
        $expected = "N'" . implode("', N'", AssignmentStatus::ALL) . "'";

        $checks = [
            'CK_onboarding_Assignments_Status',
            'CK_onboarding_CompletionProjection_Status',
            'CK_audit_OnboardingStatusEvents_ToStatus',
        ];

        foreach ($checks as $check) {
            self::assertMatchesRegularExpression(
                '/CONSTRAINT \[' . $check . "\]\s*\n?\s*CHECK \(\[\w+\] IN \(" . preg_quote($expected, '/') . '\)\)/',
                $sql,
                $check . ' must list exactly the six statuses in AssignmentStatus::ALL, in order.',
            );
        }
    }

    // -----------------------------------------------------------------
    // The classification boundary
    // -----------------------------------------------------------------

    /**
     * The split is only worth having if no query crosses it. The dependency runs
     * one way — [onboarding_hr] references [onboarding], never the reverse — so
     * a repository over the workflow schema has nothing to join to.
     */
    public function test_the_workflow_schema_never_references_the_restricted_one(): void
    {
        self::assertStringNotContainsString(
            '[onboarding_hr]',
            $this->withoutComments($this->sql(self::WORKFLOW_SCHEMA)),
            'A foreign key or a join from the workflow schema into the restricted one would undo the split. The bracketed form is what a real T-SQL reference looks like; naming the schema in prose is fine.',
        );
    }

    /**
     * C2 is a recordable answer — "somebody decided this field is ordinary" —
     * and that is what closes the fail-open gap: on a payroll or restricted
     * form every field must have a row, so absence means unclassified rather
     * than silently ordinary. C0 and C1 are content and workflow, which a
     * submitted field never is.
     *
     * Only C3 and C4 are stored in the restricted schema; a C2 row is a
     * classification decision, not a payload.
     */
    public function test_a_field_may_be_classified_ordinary_payroll_or_restricted(): void
    {
        $sql = $this->sql(self::HR_SCHEMA);

        self::assertStringContainsString('CK_onboarding_hr_FieldClassifications_Class', $sql);
        self::assertStringContainsString('CHECK ([RetentionClassId] IN (2, 3, 4))', $sql);

        // The split payload itself still admits only the two restricted classes.
        self::assertStringContainsString('CK_onboarding_hr_RestrictedSubmissionData_Class', $sql);
        self::assertMatchesRegularExpression(
            '/CK_onboarding_hr_RestrictedSubmissionData_Class\]\s*\n?\s*CHECK \(\[RetentionClassId\] IN \(3, 4\)\)/',
            $sql,
        );
    }

    /**
     * The author says, per version, whether a new version supersedes work in
     * progress. Default 0: a default that resets people's inductions is one
     * that will be regretted, and ticking a box is cheaper than un-resetting.
     */
    public function test_every_version_table_carries_the_reissue_flag(): void
    {
        $sql = $this->sql(self::WORKFLOW_SCHEMA);

        foreach (['TrainingModuleVersions', 'PolicyVersions', 'FormDefinitionVersions'] as $table) {
            $body = $this->tableBody($sql, $table);

            self::assertMatchesRegularExpression(
                '/\[SupersedesWorkInProgress\] BIT NOT NULL/',
                $body,
                $table . ' must record whether publishing disturbs work in progress.',
            );
            self::assertStringContainsString('_Supersedes] DEFAULT (0)', $body);
        }
    }

    /**
     * B4 / open question 6, answered 21 Sep 2026: keep source behaviour. The
     * payslip PIN is collected, stored and printed onto the generated PDF as the
     * source does.
     *
     * What this test holds is the boundary that decision does NOT extend to. A
     * live credential may live in the restricted schema, where reads are audited
     * and the permission gate is ONBOARDING.PEOPLE.MANAGE. It may not live in
     * the workflow schema, which ordinary queries, reports and the reviewer
     * queue all read. That is the difference between a decision and a leak.
     */
    public function test_the_payslip_pin_is_confined_to_the_restricted_schema(): void
    {
        self::assertMatchesRegularExpression(
            '/\[PayslipPin\]\s+NVARCHAR/i',
            $this->sql(self::HR_SCHEMA),
            'The owner chose to keep source behaviour, so the column exists — in onboarding_hr, classified C3.',
        );

        self::assertDoesNotMatchRegularExpression(
            '/\[\w*Pin\w*\]\s+(NVARCHAR|CHAR|INT|VARCHAR)/i',
            $this->withoutComments($this->sql(self::WORKFLOW_SCHEMA)),
            'A credential must never reach the workflow schema, whatever was decided about storing it.',
        );
    }

    // -----------------------------------------------------------------
    // Immutability — gap A5, A6
    // -----------------------------------------------------------------

    /**
     * A5 asks that an update to a submitted row FAILS rather than silently
     * succeeding. A convention inside a repository cannot promise that; the next
     * script and the next ad-hoc fix in a query window are both outside it.
     */
    public function test_the_evidence_tables_refuse_updates_and_deletes(): void
    {
        $workflow = $this->sql(self::WORKFLOW_SCHEMA);

        /* FormSubmissions is AFTER rather than INSTEAD OF: it has one permitted
           update (marking a revision superseded) and re-issuing that from inside
           an INSTEAD OF trigger is only safe while RECURSIVE_TRIGGERS is off. */
        self::assertMatchesRegularExpression(
            '/CREATE TRIGGER \[onboarding\]\.\[TR_FormSubmissions_Immutable\].*?AFTER UPDATE, DELETE/s',
            $workflow,
            'FormSubmissions must refuse content changes and deletes without depending on a database-wide trigger option.',
        );

        foreach ([
            'TR_PolicyAcknowledgements_Immutable',
            'TR_AttemptAnswers_Immutable',
            'TR_OnboardingStatusEvents_AppendOnly',
            'TR_OnboardingDocumentAccessEvents_AppendOnly',
        ] as $trigger) {
            self::assertMatchesRegularExpression(
                '/CREATE TRIGGER \[\w+\]\.\[' . $trigger . '\].*?INSTEAD OF UPDATE, DELETE/s',
                $workflow,
                $trigger . ' must block both UPDATE and DELETE.',
            );
        }

        self::assertMatchesRegularExpression(
            '/CREATE TRIGGER \[onboarding_hr\]\.\[TR_RestrictedSubmissionData_Immutable\].*?INSTEAD OF UPDATE, DELETE/s',
            $this->sql(self::HR_SCHEMA),
            'The split half of a submission is evidence exactly as the submission is.',
        );
    }

    /**
     * A6: superseding a policy must not move an acknowledgement. Binding to the
     * version rather than to the policy is what makes that structurally true.
     */
    public function test_an_acknowledgement_is_bound_to_a_policy_version(): void
    {
        $body = $this->tableBody($this->sql(self::WORKFLOW_SCHEMA), 'PolicyAcknowledgements');

        self::assertStringContainsString('[PolicyVersionId]', $body);
        self::assertStringNotContainsString('[PolicyId]', $body);
    }

    /**
     * A7: only one version of a named form reaches new starters.
     */
    public function test_one_live_version_per_form_module_and_policy(): void
    {
        $sql = $this->sql(self::WORKFLOW_SCHEMA);

        foreach ([
            'UX_onboarding_FormDefinitionVersions_OneLive',
            'UX_onboarding_TrainingModuleVersions_OneLive',
            'UX_onboarding_PolicyVersions_OneLive',
        ] as $index) {
            self::assertStringContainsString($index, $sql);
        }
    }

    /**
     * A8: the reminder job cannot double-send, because the database will not let
     * it — rather than because the job remembers to ask.
     */
    public function test_a_notification_cannot_be_sent_twice_in_a_day(): void
    {
        self::assertMatchesRegularExpression(
            '/CREATE UNIQUE NONCLUSTERED INDEX \[UX_onboarding_NotificationLog_OncePerDay\]\s*\n\s*ON \[onboarding\]\.\[NotificationLog\]\(\[UserId\], \[NotificationType\], \[SubjectType\], \[SubjectPublicId\], \[SentOn\]\)/',
            $this->sql(self::WORKFLOW_SCHEMA),
        );
    }

    // -----------------------------------------------------------------
    // The completion projection — D7
    // -----------------------------------------------------------------

    /**
     * Work order §6 and §12.10: person id, item id/type, date/status, evidence
     * reference. Every column that is not there is a column that cannot leak
     * into Skill Matrix, so the test is written as a closed list rather than as
     * a set of "must contain" assertions.
     */
    public function test_the_completion_projection_carries_nothing_extra(): void
    {
        $body = $this->tableBody($this->sql(self::WORKFLOW_SCHEMA), 'CompletionProjection');

        preg_match_all('/^\s*\[(\w+)\]\s+[A-Z]/m', $body, $matches);

        self::assertSame(
            [
                'CompletionProjectionId',
                'UserId',
                'ItemType',
                'ItemPublicId',
                'Status',
                'CompletedOn',
                'EvidencePublicId',
                'UpdatedAtUtc',
            ],
            $matches[1],
            'The completion projection is a deliberately narrow contract. Adding a column here widens what Skill Matrix can read.',
        );
    }

    // -----------------------------------------------------------------
    // Identity linkage — work order §5 and §6
    // -----------------------------------------------------------------

    /**
     * Onboarding links to people by identity.Users.UserId only. An email address
     * column would be a second copy of platform identity, and it would break
     * ownership the day somebody's address changes — which for this module is
     * their first morning, when a candidate on a personal address is issued a
     * TriCab mailbox.
     */
    public function test_no_table_stores_a_persons_email_address_or_name(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\[\w*EmailAddress\w*\]\s+NVARCHAR/i',
            $this->withoutComments($this->sql(self::WORKFLOW_SCHEMA)),
        );

        // The HR schema holds two, and both are somebody else's, on purpose:
        // a payslip destination and an emergency contact's address. Neither
        // identifies the platform user, who is named by UserId alone.
        $hr = $this->sql(self::HR_SCHEMA);
        preg_match_all('/\[(\w*Email\w*)\]\s+NVARCHAR/i', $hr, $matches);

        self::assertSame(['PayslipEmailAddress', 'EmailAddress'], $matches[1]);
    }

    /**
     * The DDL with its comments removed, for the assertions that are about what
     * the schema DOES rather than about what it explains. Several comments name
     * the boundary they exist to describe.
     */
    private function withoutComments(string $sql): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $sql);
    }

    /**
     * Returns the CREATE TABLE body for one table, so a column assertion cannot
     * accidentally match a mention of that column somewhere else in the file.
     */
    private function tableBody(string $sql, string $table): string
    {
        $pattern = '/CREATE TABLE \[\w+\]\.\[' . preg_quote($table, '/') . '\] \((.*?)\n    \);/s';

        self::assertMatchesRegularExpression($pattern, $sql, sprintf('No CREATE TABLE found for %s.', $table));
        preg_match($pattern, $sql, $matches);

        return $matches[1];
    }
}
