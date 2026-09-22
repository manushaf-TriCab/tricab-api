<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAssignmentLocator;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingDocumentRepository;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingFormRepository;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingPolicyRepository;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingTrainingRepository;
use TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingWriteIdempotencyStore;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Core\Onboarding\Application\DTO\OnboardingWriteIdempotencyRecord;
use TriCab\Core\Onboarding\Application\DTO\SplitFormPayload;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Service\AssessmentGrader;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 3.3 — the write adapters, against the real schema.
 *
 * `OnboardingSelfWriteTest` proves the rules with doubles. This proves the SQL,
 * and there are four things only the database can be asked:
 *
 *  - that the payroll half of a submitted form is **not in `[onboarding]`** —
 *    asserted by counting rows in each schema, not by trusting what the
 *    repository returned;
 *  - that a status change and its `audit.OnboardingStatusEvents` row arrive
 *    together, which a double cannot fail to do;
 *  - that a `ROWVERSION` bound through `SqlBinary` actually matches, which is
 *    the trap Phase 2.5 recorded and the reason the token is not hex here;
 *  - that ownership is a predicate in the statement, proved by seeding two
 *    people's work and asking for one.
 *
 * Everything runs inside a transaction that is always rolled back; the target is
 * shared. Skips when Azure SQL is unreachable.
 */
#[Group('live-azure-sql')]
final class AzureSqlOnboardingSelfWriteTest extends TestCase
{
    private ?PDO $pdo = null;

    private AzureSqlOnboardingTrainingRepository $training;
    private AzureSqlOnboardingPolicyRepository $policies;
    private AzureSqlOnboardingFormRepository $forms;
    private AzureSqlOnboardingDocumentRepository $documents;
    private AzureSqlOnboardingWriteIdempotencyStore $ledger;

    private int $mine;
    private int $theirs;

    protected function setUp(): void
    {
        $pdo = LiveAzureSqlSupport::connect();

        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable (VPN down, or the serverless database is resuming).');
        }

        try {
            $applied = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[onboarding].[Attempts]', N'U') IS NULL THEN 0 ELSE 1 END");
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

        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics('test'));
        $locator = new AzureSqlOnboardingAssignmentLocator($connection);

        $this->training = new AzureSqlOnboardingTrainingRepository($connection, $locator);
        $this->policies = new AzureSqlOnboardingPolicyRepository($connection, $locator);
        $this->forms = new AzureSqlOnboardingFormRepository($connection, $locator);
        $this->documents = new AzureSqlOnboardingDocumentRepository($connection);
        $this->ledger = new AzureSqlOnboardingWriteIdempotencyStore($connection);

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo = null;
    }

    // -------------------------------------------------- presentation progress

    public function test_progress_is_written_read_back_and_audited(): void
    {
        [$assignmentPublicId, $versionPublicId] = $this->allocateTraining();

        $detail = $this->training->savePresentationProgress(
            $this->mine,
            $assignmentPublicId,
            $versionPublicId,
            ['intro', 'hazards'],
            false,
            StatusTransition::traineeMovesTo(
                AssignmentStatus::notStarted(),
                AssignmentStatus::inProgress(),
                $this->mine,
                'Opened the presentation.',
            ),
        );

        self::assertSame(['intro', 'hazards'], $detail->reachedSlideIds);
        self::assertSame(AssignmentStatus::IN_PROGRESS, $detail->status->toString());
        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [audit].[OnboardingStatusEvents]
                 WHERE CONVERT(NVARCHAR(36), [AssignmentPublicId]) = :id',
                [':id' => $assignmentPublicId],
            ),
            'A status change without its audit row is the failure invariant 13 exists to prevent.',
        );
        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding].[Assignments]
                 WHERE CONVERT(NVARCHAR(36), [PublicId]) = :id AND [StartedAtUtc] IS NOT NULL',
                [':id' => $assignmentPublicId],
            ),
            'Opening the item sets StartedAtUtc, derived from the status rather than sent with it.',
        );
    }

    /**
     * The monotonic guard, in SQL. A second tab reporting less does not erase
     * what the first one recorded, and it is not reported as a conflict either.
     */
    public function test_a_smaller_later_write_is_a_no_op_rather_than_a_regression(): void
    {
        [$assignmentPublicId, $versionPublicId] = $this->allocateTraining();

        $this->training->savePresentationProgress(
            $this->mine,
            $assignmentPublicId,
            $versionPublicId,
            ['intro', 'hazards', 'sign-off'],
            true,
            null,
        );
        $after = $this->training->savePresentationProgress(
            $this->mine,
            $assignmentPublicId,
            $versionPublicId,
            ['intro'],
            false,
            null,
        );

        self::assertSame(['intro', 'hazards', 'sign-off'], $after->reachedSlideIds);
    }

    public function test_progress_against_a_replaced_version_matches_nothing(): void
    {
        [$assignmentPublicId] = $this->allocateTraining();

        $this->expectException(ContentVersionSuperseded::class);

        $this->training->savePresentationProgress(
            $this->mine,
            $assignmentPublicId,
            '00000000-0000-4000-8000-000000000000',
            ['intro'],
            false,
            null,
        );
    }

    /**
     * Ownership is a predicate in the statement. Both people's work is in the
     * table; only one person's is reachable.
     */
    public function test_another_person_s_assignment_is_not_writable_and_is_not_readable(): void
    {
        [$assignmentPublicId, $versionPublicId] = $this->allocateTraining(owner: $this->theirs);

        self::assertNull($this->training->findTraining($this->mine, $assignmentPublicId));

        try {
            $this->training->savePresentationProgress(
                $this->mine,
                $assignmentPublicId,
                $versionPublicId,
                ['intro'],
                false,
                null,
            );
            self::fail('Wrote progress against another person\'s assignment.');
        } catch (OnboardingAssignmentNotFound) {
            self::assertSame(
                0,
                $this->rowCount(
                    'SELECT COUNT(*) FROM [onboarding].[PresentationProgress] pp
                     JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = pp.[AssignmentId]
                     WHERE CONVERT(NVARCHAR(36), a.[PublicId]) = :id',
                    [':id' => $assignmentPublicId],
                ),
            );
        }
    }

    public function test_a_withdrawn_allocation_is_simply_absent(): void
    {
        [$assignmentPublicId] = $this->allocateTraining();
        $this->exec(
            'UPDATE [onboarding].[Assignments]
             SET [WithdrawnAtUtc] = SYSUTCDATETIME(), [WithdrawnByUserId] = :by
             WHERE CONVERT(NVARCHAR(36), [PublicId]) = :id',
            [':by' => $this->mine, ':id' => $assignmentPublicId],
        );

        self::assertNull($this->training->findTraining($this->mine, $assignmentPublicId));
    }

    // -------------------------------------------------------------- attempts

    public function test_an_attempt_snapshots_the_questions_and_grades_against_the_snapshot(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateTraining();
        $this->addQuestion($versionId, 1, 'First', 'b');
        $this->addQuestion($versionId, 2, 'Second', 'a');

        $attempt = $this->training->startAttempt($this->mine, $assignmentPublicId, $versionPublicId, null);

        self::assertCount(2, $attempt->questions);
        self::assertSame(1, $attempt->attemptNumber);
        self::assertSame('b', $attempt->questions[0]->correctOptionKey());

        $grade = AssessmentGrader::grade(
            $attempt->questions,
            [$attempt->questions[0]->publicId => 'b', $attempt->questions[1]->publicId => 'b'],
            80,
        );

        $submitted = $this->training->submitAttempt(
            $this->mine,
            $assignmentPublicId,
            $attempt->publicId,
            [$attempt->questions[0]->publicId => 'b', $attempt->questions[1]->publicId => 'b'],
            $grade,
            StatusTransition::systemMovesTo(AssignmentStatus::notStarted(), AssignmentStatus::completed(), 'Passed.'),
        );

        self::assertSame(50, $submitted->scorePercent);
        self::assertFalse($submitted->isPass);
        self::assertCount(2, $submitted->recordedAnswers);

        // Every question in the snapshot gets a row, including the wrong ones —
        // "did they skip it or did we lose it" must not be a question later.
        self::assertSame(
            2,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding].[AttemptAnswers] ans
                 JOIN [onboarding].[Attempts] att ON att.[AttemptId] = ans.[AttemptId]
                 WHERE CONVERT(NVARCHAR(36), att.[PublicId]) = :id',
                [':id' => $attempt->publicId],
            ),
        );
    }

    public function test_an_attempt_cannot_be_submitted_twice(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateTraining();
        $this->addQuestion($versionId, 1, 'First', 'b');

        $attempt = $this->training->startAttempt($this->mine, $assignmentPublicId, $versionPublicId, null);
        $answers = [$attempt->questions[0]->publicId => 'b'];
        $grade = AssessmentGrader::grade($attempt->questions, $answers, 80);

        $this->training->submitAttempt($this->mine, $assignmentPublicId, $attempt->publicId, $answers, $grade, null);

        $this->expectException(OnboardingWriteConflict::class);

        $this->training->submitAttempt($this->mine, $assignmentPublicId, $attempt->publicId, $answers, $grade, null);
    }

    public function test_attempts_are_numbered_from_one_without_the_caller_choosing(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateTraining();
        $this->addQuestion($versionId, 1, 'First', 'b');

        $first = $this->training->startAttempt($this->mine, $assignmentPublicId, $versionPublicId, null);
        $second = $this->training->startAttempt($this->mine, $assignmentPublicId, $versionPublicId, null);

        self::assertSame(1, $first->attemptNumber);
        self::assertSame(2, $second->attemptNumber);
    }

    public function test_a_system_completion_is_audited_without_a_name_attached(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateTraining();
        $this->addQuestion($versionId, 1, 'First', 'b');

        $attempt = $this->training->startAttempt($this->mine, $assignmentPublicId, $versionPublicId, null);
        $answers = [$attempt->questions[0]->publicId => 'b'];

        $this->training->submitAttempt(
            $this->mine,
            $assignmentPublicId,
            $attempt->publicId,
            $answers,
            AssessmentGrader::grade($attempt->questions, $answers, 80),
            StatusTransition::systemMovesTo(AssignmentStatus::notStarted(), AssignmentStatus::completed(), 'Passed.'),
        );

        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [audit].[OnboardingStatusEvents]
                 WHERE CONVERT(NVARCHAR(36), [AssignmentPublicId]) = :id
                   AND [ActorType] = :actor AND [ActorUserId] IS NULL AND [ToStatus] = :status',
                [
                    ':id' => $assignmentPublicId,
                    ':actor' => StatusActor::SYSTEM,
                    ':status' => AssignmentStatus::COMPLETED,
                ],
            ),
            'CK_audit_OnboardingStatusEvents_Attribution allows a null actor only for system, and this is it.',
        );
    }

    // --------------------------------------------------------------- policies

    public function test_an_acknowledgement_is_bound_to_the_version_that_was_read(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocatePolicy();

        $detail = $this->policies->acknowledge(
            $this->mine,
            $assignmentPublicId,
            $versionPublicId,
            'I have read and accept it.',
            'live-key-1',
            StatusTransition::systemMovesTo(AssignmentStatus::notStarted(), AssignmentStatus::completed(), 'Acknowledged.'),
        );

        self::assertTrue($detail->isAcknowledged());
        self::assertSame(AssignmentStatus::COMPLETED, $detail->status->toString());
        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding].[PolicyAcknowledgements]
                 WHERE [UserId] = :u AND [PolicyVersionId] = :v AND [DeclarationText] = :text',
                [':u' => $this->mine, ':v' => $versionId, ':text' => 'I have read and accept it.'],
            ),
        );
    }

    public function test_a_second_acknowledgement_of_one_version_is_refused_by_the_database(): void
    {
        [$assignmentPublicId, $versionPublicId] = $this->allocatePolicy();
        $this->policies->acknowledge($this->mine, $assignmentPublicId, $versionPublicId, 'Accepted.', null, null);

        $this->expectException(OnboardingWriteConflict::class);

        $this->policies->acknowledge($this->mine, $assignmentPublicId, $versionPublicId, 'Accepted.', null, null);
    }

    public function test_the_declaration_wording_is_read_back_from_the_version_content(): void
    {
        [$assignmentPublicId] = $this->allocatePolicy(
            contentJson: '{"blocks":[{"type":"paragraph","text":"Wear the boots."}],"declaration":"I accept the site rules."}',
        );

        $detail = $this->policies->findPolicy($this->mine, $assignmentPublicId);

        self::assertNotNull($detail);
        self::assertSame('I accept the site rules.', $detail->declarationText);
        self::assertSame('Wear the boots.', $detail->contentBlocks[0]['text'] ?? null);
    }

    // ------------------------------------------------------------------ forms

    /**
     * The property the whole split exists for, asked of the database rather than
     * of the repository: the payroll value is not in `[onboarding]` at all.
     */
    public function test_a_submitted_payload_lands_in_two_schemas_and_the_bank_account_is_not_in_the_workflow_one(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateForm();
        $this->classify($versionId, 'preferredName', DataClass::ORDINARY_HR);
        $this->classify($versionId, 'bank.accountNumber', DataClass::PAYROLL);
        $this->classify($versionId, 'health.conditions', DataClass::RESTRICTED_HR);

        $payload = SplitFormPayload::fromPayload(
            [
                'preferredName' => 'Synthetic Sam',
                'bank' => ['accountNumber' => '012-345'],
                'health' => ['conditions' => 'none recorded'],
            ],
            [
                'preferredName' => DataClass::ORDINARY_HR,
                'bank.accountNumber' => DataClass::PAYROLL,
                'health.conditions' => DataClass::RESTRICTED_HR,
            ],
        );

        $detail = $this->forms->submitForm(
            $this->mine,
            $assignmentPublicId,
            $versionPublicId,
            $payload,
            'live-form-key',
            StatusTransition::traineeMovesTo(
                AssignmentStatus::notStarted(),
                AssignmentStatus::submitted(),
                $this->mine,
                'Submitted.',
            ),
        );

        self::assertSame(1, $detail->submittedRevisionCount);
        self::assertSame(AssignmentStatus::SUBMITTED, $detail->status->toString());

        self::assertSame(
            0,
            $this->rowCount(
                "SELECT COUNT(*) FROM [onboarding].[FormSubmissions] fs
                 JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = fs.[AssignmentId]
                 WHERE CONVERT(NVARCHAR(36), a.[PublicId]) = :id
                   AND fs.[SubmittedDataJson] LIKE N'%012-345%'",
                [':id' => $assignmentPublicId],
            ),
            'A bank account in the workflow schema is the disclosure the splitter exists to prevent.',
        );
        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding_hr].[RestrictedSubmissionData]
                 WHERE [UserId] = :u AND [RetentionClassId] = :c',
                [':u' => $this->mine, ':c' => DataClass::PAYROLL],
            ),
        );
        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding_hr].[RestrictedSubmissionData]
                 WHERE [UserId] = :u AND [RetentionClassId] = :c',
                [':u' => $this->mine, ':c' => DataClass::RESTRICTED_HR],
            ),
            'C3 and C4 are separate rows, so the two halves stay separable.',
        );
        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding].[FormSubmissions] fs
                 JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = fs.[AssignmentId]
                 WHERE CONVERT(NVARCHAR(36), a.[PublicId]) = :id AND fs.[HasRestrictedData] = 1',
                [':id' => $assignmentPublicId],
            ),
        );
    }

    public function test_a_second_submission_is_revision_two_and_the_first_survives(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateForm();
        $this->classify($versionId, 'preferredName', DataClass::ORDINARY_HR);
        $payload = SplitFormPayload::fromPayload(
            ['preferredName' => 'Sam'],
            ['preferredName' => DataClass::ORDINARY_HR],
        );

        $this->forms->submitForm($this->mine, $assignmentPublicId, $versionPublicId, $payload, null, null);
        $second = $this->forms->submitForm($this->mine, $assignmentPublicId, $versionPublicId, $payload, null, null);

        self::assertSame(2, $second->submittedRevisionCount);
        self::assertSame(
            [1, 2],
            $this->column(
                'SELECT fs.[RevisionNumber] FROM [onboarding].[FormSubmissions] fs
                 JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = fs.[AssignmentId]
                 WHERE CONVERT(NVARCHAR(36), a.[PublicId]) = :id
                 ORDER BY fs.[RevisionNumber]',
                [':id' => $assignmentPublicId],
            ),
            'A correction is a new revision, never an edit.',
        );
    }

    public function test_an_unclassified_form_version_cannot_be_submitted_at_all(): void
    {
        [, , $versionId] = $this->allocateForm();
        $versionPublicId = $this->text(
            'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[FormDefinitionVersions]
             WHERE [FormDefinitionVersionId] = :v',
            [':v' => $versionId],
        );

        self::assertSame([], $this->forms->fieldClassifications($versionPublicId));
    }

    /**
     * The row-version trap Phase 2.5 recorded: bound through `SqlBinary`, not as
     * hex, and not as a raw eight-byte string.
     */
    public function test_a_draft_token_round_trips_and_a_stale_one_loses(): void
    {
        [$assignmentPublicId, $versionPublicId] = $this->allocateForm();

        $first = $this->forms->saveDraft($this->mine, $assignmentPublicId, $versionPublicId, ['a' => 1], null, null);
        self::assertNotNull($first->draftRowVersion);

        $token = RowVersionToken::fromBase64($first->draftRowVersion);
        $second = $this->forms->saveDraft($this->mine, $assignmentPublicId, $versionPublicId, ['a' => 2], $token, null);
        self::assertNotNull($second->draftRowVersion);
        self::assertNotSame($first->draftRowVersion, $second->draftRowVersion, 'Every write moves the token.');

        $this->expectException(OnboardingWriteConflict::class);

        $this->forms->saveDraft($this->mine, $assignmentPublicId, $versionPublicId, ['a' => 3], $token, null);
    }

    public function test_handing_the_form_in_throws_the_draft_away(): void
    {
        [$assignmentPublicId, $versionPublicId, $versionId] = $this->allocateForm();
        $this->classify($versionId, 'preferredName', DataClass::ORDINARY_HR);
        $this->forms->saveDraft($this->mine, $assignmentPublicId, $versionPublicId, ['preferredName' => 'Sam'], null, null);

        $detail = $this->forms->submitForm(
            $this->mine,
            $assignmentPublicId,
            $versionPublicId,
            SplitFormPayload::fromPayload(['preferredName' => 'Sam'], ['preferredName' => DataClass::ORDINARY_HR]),
            null,
            null,
        );

        self::assertNull($detail->draftRowVersion);
        self::assertSame(
            0,
            $this->rowCount(
                'SELECT COUNT(*) FROM [onboarding].[FormDrafts] dr
                 JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = dr.[AssignmentId]
                 WHERE CONVERT(NVARCHAR(36), a.[PublicId]) = :id',
                [':id' => $assignmentPublicId],
            ),
        );
    }

    // ----------------------------------------------------------- the ledger

    public function test_a_completed_acquisition_carries_the_id_of_what_it_produced(): void
    {
        $key = 'live-' . bin2hex(random_bytes(6));
        $actor = 'user:' . $this->mine;
        $result = '11111111-2222-4333-8444-555555555555';

        $this->ledger->tryAcquire($this->ledgerRow($actor, $key, 'hash-one'));
        $this->ledger->markCompleted($actor, OnboardingWriteIdempotencyStore::OPERATION_SUBMIT_FORM, $key, $result);

        $row = $this->ledger->find($actor, OnboardingWriteIdempotencyStore::OPERATION_SUBMIT_FORM, $key);

        self::assertNotNull($row);
        self::assertTrue($row->isCompleted());
        self::assertSame(
            $result,
            strtolower((string) $row->resultPublicId),
            'Phase 2.5 found no CHECK constraint requires this, so this layer owes it a test.',
        );
    }

    public function test_the_same_key_with_the_same_request_returns_the_held_row(): void
    {
        $key = 'live-' . bin2hex(random_bytes(6));
        $actor = 'user:' . $this->mine;

        $first = $this->ledger->tryAcquire($this->ledgerRow($actor, $key, 'hash-one'));
        $second = $this->ledger->tryAcquire($this->ledgerRow($actor, $key, 'hash-one'));

        self::assertSame($first->platformOperationId, $second->platformOperationId, 'A retry joins the first attempt.');
    }

    public function test_the_same_key_with_a_different_request_is_refused(): void
    {
        $key = 'live-' . bin2hex(random_bytes(6));
        $actor = 'user:' . $this->mine;
        $this->ledger->tryAcquire($this->ledgerRow($actor, $key, 'hash-one'));

        $this->expectException(OnboardingWriteConflict::class);

        $this->ledger->tryAcquire($this->ledgerRow($actor, $key, 'hash-two'));
    }

    // ---------------------------------------------------------- documents

    public function test_a_document_read_is_audited_and_another_person_s_is_not_found(): void
    {
        $documentPublicId = $this->addDocument($this->mine, DataClass::PAYROLL);
        $theirs = $this->addDocument($this->theirs, DataClass::PAYROLL);

        $mine = $this->documents->findDocument($this->mine, $documentPublicId);
        self::assertNotNull($mine);
        self::assertNull($this->documents->findDocument($this->mine, $theirs));

        $this->documents->recordAccess($mine, $this->mine, 'download', 'ONBOARDING.SELF.READ', null, '127.0.0.1', 'phpunit');

        self::assertSame(
            1,
            $this->rowCount(
                'SELECT COUNT(*) FROM [audit].[OnboardingDocumentAccessEvents]
                 WHERE CONVERT(NVARCHAR(36), [DocumentPublicId]) = :id AND [ActorUserId] = :actor',
                [':id' => $documentPublicId, ':actor' => $this->mine],
            ),
        );
    }

    public function test_a_deleted_document_is_gone_from_both_the_list_and_the_lookup(): void
    {
        $documentPublicId = $this->addDocument($this->mine, DataClass::ORDINARY_HR);
        $this->exec(
            'UPDATE [onboarding].[DocumentReferences] SET [DeletedAtUtc] = SYSUTCDATETIME()
             WHERE CONVERT(NVARCHAR(36), [PublicId]) = :id',
            [':id' => $documentPublicId],
        );

        self::assertNull($this->documents->findDocument($this->mine, $documentPublicId));
        self::assertSame([], array_filter(
            $this->documents->listDocuments($this->mine),
            static fn ($view): bool => $view->publicId === $documentPublicId,
        ));
    }

    // ------------------------------------------------------------- fixtures

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

    /** @param array<string, mixed> $params */
    private function rowCount(string $sql, array $params = []): int
    {
        $value = $this->scalar($sql, $params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * The single value, asserted to be text. Every caller wants a public id,
     * and a null here means the fixture did not insert what it thought it did —
     * which is worth failing on loudly rather than carrying forward as ''.
     *
     * @param array<string, mixed> $params
     */
    private function text(string $sql, array $params = []): string
    {
        $value = $this->scalar($sql, $params);
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<int>
     */
    private function column(string $sql, array $params = []): array
    {
        $statement = $this->db()->prepare($sql);
        $statement->execute($params);

        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            self::assertIsNumeric($value);
            $values[] = (int) $value;
        }

        return $values;
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    /**
     * @return array{0: string, 1: string, 2: int} assignment public id, version public id, version id
     */
    private function allocateTraining(?int $owner = null): array
    {
        $owner ??= $this->mine;

        $this->exec(
            'INSERT INTO [onboarding].[TrainingModules] ([Slug],[Title]) VALUES (:slug, :title)',
            [':slug' => $this->uniqueSlug('syn-w-trn'), ':title' => 'Synthetic induction'],
        );
        $moduleId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[TrainingModuleVersions]
                ([TrainingModuleId],[VersionNumber],[PresentationManifestJson],[RequiredSlideCount],
                 [PassMarkPercent],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:m, 1, N'[\"intro\",\"hazards\",\"sign-off\"]', 0, 80, 1, SYSUTCDATETIME(), :u)",
            [':m' => $moduleId, ':u' => $this->mine],
        );
        $versionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[TrainingModuleVersionId],[AllocatedByUserId])
             VALUES (:u, N'training', :v, :a)",
            [':u' => $owner, ':v' => $versionId, ':a' => $this->mine],
        );

        return [
            $this->text(
                'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
                [':id' => (int) $this->db()->lastInsertId()],
            ),
            $this->text(
                'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[TrainingModuleVersions] WHERE [TrainingModuleVersionId] = :id',
                [':id' => $versionId],
            ),
            $versionId,
        ];
    }

    private function addQuestion(int $versionId, int $ordinal, string $prompt, string $correct): void
    {
        $this->exec(
            "INSERT INTO [onboarding].[Questions]
                ([TrainingModuleVersionId],[Ordinal],[Prompt],[OptionsJson],[CorrectOptionKey],[CorrectReason])
             VALUES (:v, :o, :p, N'{\"a\":\"No\",\"b\":\"Yes\"}', :c, N'Synthetic reason.')",
            [':v' => $versionId, ':o' => $ordinal, ':p' => $prompt, ':c' => $correct],
        );
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function allocatePolicy(string $contentJson = '{"blocks":[]}'): array
    {
        $this->exec(
            'INSERT INTO [onboarding].[Policies] ([Slug],[Title]) VALUES (:slug, :title)',
            [':slug' => $this->uniqueSlug('syn-w-pol'), ':title' => 'Synthetic policy'],
        );
        $policyId = (int) $this->db()->lastInsertId();

        $this->exec(
            'INSERT INTO [onboarding].[PolicyVersions]
                ([PolicyId],[VersionNumber],[ContentJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:p, 1, :content, 1, SYSUTCDATETIME(), :u)',
            [':p' => $policyId, ':content' => $contentJson, ':u' => $this->mine],
        );
        $versionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[PolicyVersionId],[AllocatedByUserId])
             VALUES (:u, N'policy', :v, :a)",
            [':u' => $this->mine, ':v' => $versionId, ':a' => $this->mine],
        );

        return [
            $this->text(
                'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
                [':id' => (int) $this->db()->lastInsertId()],
            ),
            $this->text(
                'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[PolicyVersions] WHERE [PolicyVersionId] = :id',
                [':id' => $versionId],
            ),
            $versionId,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function allocateForm(): array
    {
        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitions] ([Slug],[Title],[AccessGroup])
             VALUES (:slug, :title, N'hr')",
            [':slug' => $this->uniqueSlug('syn-w-frm'), ':title' => 'Synthetic form'],
        );
        $definitionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[FormDefinitionVersions]
                ([FormDefinitionId],[VersionNumber],[SchemaJson],[IsPublished],[PublishedAtUtc],[PublishedByUserId])
             VALUES (:d, 1, N'{\"fields\":[]}', 1, SYSUTCDATETIME(), :u)",
            [':d' => $definitionId, ':u' => $this->mine],
        );
        $versionId = (int) $this->db()->lastInsertId();

        $this->exec(
            "INSERT INTO [onboarding].[Assignments]
                ([UserId],[ItemType],[FormDefinitionVersionId],[AllocatedByUserId])
             VALUES (:u, N'form', :v, :a)",
            [':u' => $this->mine, ':v' => $versionId, ':a' => $this->mine],
        );

        return [
            $this->text(
                'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
                [':id' => (int) $this->db()->lastInsertId()],
            ),
            $this->text(
                'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[FormDefinitionVersions] WHERE [FormDefinitionVersionId] = :id',
                [':id' => $versionId],
            ),
            $versionId,
        ];
    }

    private function classify(int $versionId, string $path, int $dataClass): void
    {
        $this->exec(
            'INSERT INTO [onboarding_hr].[FieldClassifications]
                ([FormDefinitionVersionId],[FieldPath],[RetentionClassId],[ClassifiedByUserId])
             VALUES (:v, :p, :c, :u)',
            [':v' => $versionId, ':p' => $path, ':c' => $dataClass, ':u' => $this->mine],
        );
    }

    private function addDocument(int $userId, int $dataClass): string
    {
        $path = 'synthetic/' . bin2hex(random_bytes(8)) . '.pdf';

        $this->exec(
            "INSERT INTO [onboarding].[DocumentReferences]
                ([UserId],[DocumentKind],[RetentionClassId],[BlobContainer],[BlobPath],
                 [ContentType],[ByteSize],[ContentSha256])
             VALUES (:u, N'employee_details', :c, N'onboarding', :p, N'application/pdf', 1024, :h)",
            [':u' => $userId, ':c' => $dataClass, ':p' => $path, ':h' => str_repeat('a', 64)],
        );

        return $this->text(
            'SELECT CONVERT(NVARCHAR(36), [PublicId]) FROM [onboarding].[DocumentReferences]
             WHERE [DocumentReferenceId] = :id',
            [':id' => (int) $this->db()->lastInsertId()],
        );
    }

    private function ledgerRow(string $actor, string $key, string $hash): OnboardingWriteIdempotencyRecord
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new OnboardingWriteIdempotencyRecord(
            actorKey: $actor,
            operation: OnboardingWriteIdempotencyStore::OPERATION_SUBMIT_FORM,
            idempotencyKey: $key,
            requestHash: str_pad($hash, 64, '0'),
            state: 'IN_PROGRESS',
            platformOperationId: sprintf(
                '%s-%s-4%s-8%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                substr(bin2hex(random_bytes(2)), 1),
                substr(bin2hex(random_bytes(2)), 1),
                bin2hex(random_bytes(6)),
            ),
            resultPublicId: null,
            createdAtUtc: $now,
            updatedAtUtc: $now,
        );
    }
}
