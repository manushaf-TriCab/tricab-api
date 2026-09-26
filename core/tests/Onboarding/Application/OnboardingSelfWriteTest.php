<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Application\Service\DecideReviewService;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReviewRepository;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentInput;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Application\Service\GenerateAssessmentDocumentService;
use TriCab\Core\Onboarding\Application\Service\GenerateFormSubmissionDocumentService;
use TriCab\Core\Onboarding\Contract\OnboardingAssessmentDocumentRenderer;
use TriCab\Core\Onboarding\Contract\OnboardingFormDocumentRenderer;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentStore;
use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Application\Service\AcknowledgePolicyService;
use TriCab\Core\Onboarding\Application\Service\ListOwnDocumentsService;
use TriCab\Core\Onboarding\Application\Service\OnboardingIdempotentWrite;
use TriCab\Core\Onboarding\Application\Service\OnboardingSubmissionGate;
use TriCab\Core\Onboarding\Application\Service\OpenOwnDocumentService;
use TriCab\Core\Onboarding\Application\Service\RecordPresentationProgressService;
use TriCab\Core\Onboarding\Application\Service\SaveFormDraftService;
use TriCab\Core\Onboarding\Application\Service\StartAssessmentAttemptService;
use TriCab\Core\Onboarding\Application\Service\SubmitAssessmentAttemptService;
use TriCab\Core\Onboarding\Application\Service\SubmitFormService;
use TriCab\Core\Onboarding\Domain\Exception\AssessmentNotAvailable;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingDocumentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingSubmissionWindowClosed;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Service\DocumentDeliveryPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPolicyRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingTrainingRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingWriteIdempotencyStore;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Onboarding\Domain\SignatureFieldTest;

/**
 * Phase 3.3 — the write side, against doubles.
 *
 * The live Azure SQL test proves the SQL. This proves the rules, and the rules
 * are mostly about what a request is NOT allowed to decide: which version it is
 * writing against, what its own mark is, whether an item is finished, and what
 * wording it is agreeing to.
 */
final class OnboardingSelfWriteTest extends TestCase
{
    private const TODAY = '2026-09-22 09:00:00';

    private const TRAINEE = 601;
    private const SOMEBODY_ELSE = 602;

    private const TRAINING = 'assignment-training';
    private const POLICY = 'assignment-policy';
    private const FORM = 'assignment-form';

    private const TRAINING_VERSION = 'version-training-3';
    private const POLICY_VERSION = 'version-policy-2';
    private const FORM_VERSION = 'version-form-1';

    private InMemoryOnboardingSelfServiceRepository $records;
    private InMemoryOnboardingTrainingRepository $training;
    private InMemoryOnboardingPolicyRepository $policies;
    private InMemoryOnboardingFormRepository $forms;
    private InMemoryOnboardingDocumentRepository $documents;
    private InMemoryOnboardingWriteIdempotencyStore $ledger;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->records = new InMemoryOnboardingSelfServiceRepository();
        $this->training = new InMemoryOnboardingTrainingRepository();
        $this->policies = new InMemoryOnboardingPolicyRepository();
        $this->forms = new InMemoryOnboardingFormRepository();
        $this->documents = new InMemoryOnboardingDocumentRepository();
        $this->ledger = new InMemoryOnboardingWriteIdempotencyStore();
        $this->clock = FixedClock::utc(self::TODAY);

        $this->records->withRecord(self::TRAINEE, new OnboardingTraineeRecord('trainee-1', '2026-10-01', null));
        $this->records->withRecord(self::SOMEBODY_ELSE, new OnboardingTraineeRecord('trainee-2', '2026-10-01', null));
    }

    // ------------------------------------------------- presentation progress

    public function test_recording_progress_opens_the_item_and_stores_the_slides(): void
    {
        $this->givenTraining();

        $detail = $this->progress()->record(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION, ['intro']);

        self::assertSame(['intro'], $detail->reachedSlideIds);
        self::assertSame(AssignmentStatus::IN_PROGRESS, $detail->status->toString());
        self::assertCount(1, $this->training->transitions);
        self::assertSame(StatusActor::TRAINEE, $this->training->transitions[0]->actor->toString());
        self::assertSame(self::TRAINEE, $this->training->transitions[0]->actorUserId);
    }

    /**
     * Fifty rows saying in_progress → in_progress is how a real transition gets
     * missed in the audit trail.
     */
    public function test_progress_on_an_already_open_item_records_no_second_transition(): void
    {
        $this->givenTraining();
        $this->progress()->record(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION, ['intro']);
        $this->progress()->record(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION, ['hazards']);

        self::assertCount(1, $this->training->transitions);
    }

    public function test_progress_never_completes_the_module(): void
    {
        $this->givenTraining();

        $detail = $this->progress()->record(
            self::TRAINEE,
            self::TRAINING,
            self::TRAINING_VERSION,
            ['intro', 'hazards', 'sign-off'],
        );

        self::assertTrue($detail->isPresentationComplete(), 'The deck is finished…');
        self::assertSame(AssignmentStatus::IN_PROGRESS, $detail->status->toString(), '…but the item is not.');
    }

    public function test_progress_against_a_superseded_version_is_refused(): void
    {
        $this->givenTraining();

        $this->expectException(ContentVersionSuperseded::class);

        $this->progress()->record(self::TRAINEE, self::TRAINING, 'version-training-2', ['intro']);
    }

    /**
     * Invariant 4. Somebody else's assignment id answers exactly as a mistyped
     * one does, so it cannot be used to discover that the id is real.
     */
    public function test_another_person_s_assignment_is_not_found_rather_than_forbidden(): void
    {
        $this->givenTraining(self::SOMEBODY_ELSE);

        $this->expectException(OnboardingAssignmentNotFound::class);

        $this->progress()->record(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION, ['intro']);
    }

    // ------------------------------------------------------------- assessment

    public function test_an_attempt_cannot_be_started_before_the_deck_is_finished(): void
    {
        $this->givenTraining();

        $this->expectException(AssessmentNotAvailable::class);
        $this->expectExceptionMessageMatches('/whole presentation/');

        $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);
    }

    public function test_a_started_attempt_hands_over_questions_without_their_answers(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off']);

        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);
        $encoded = json_encode($attempt->toArray(), JSON_THROW_ON_ERROR);

        self::assertCount(2, $attempt->questions);
        self::assertStringNotContainsString('correctOptionKey', $encoded);
        self::assertStringNotContainsString('correctReason', $encoded);
        self::assertNull($attempt->isPass);
    }

    public function test_a_module_with_no_questions_has_no_assessment_to_start(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off'], questions: []);

        $this->expectException(AssessmentNotAvailable::class);
        $this->expectExceptionMessageMatches('/no assessment/');

        $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);
    }

    public function test_passing_completes_the_module_as_the_system_rather_than_as_the_trainee(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off']);
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);

        $graded = $this->submitAttempt()->submit(
            self::TRAINEE,
            self::TRAINING,
            $attempt->publicId,
            self::TRAINING_VERSION,
            ['q1' => 'b', 'q2' => 'b'],
            'key-pass',
        );

        self::assertTrue($graded->isPass);
        self::assertSame(100, $graded->scorePercent);

        $completion = end($this->training->transitions);
        self::assertNotFalse($completion);
        self::assertSame(AssignmentStatus::COMPLETED, $completion->to->toString());
        self::assertSame(StatusActor::SYSTEM, $completion->actor->toString());
        self::assertNull($completion->actorUserId, 'A system action carries no name, and the schema requires that.');
    }

    /**
     * A failed attempt says what was chosen and whether it was right, and
     * never which option is: the re-sit draws the same questions, so that key
     * would be the next attempt's key (Phase 8, owner decision 25 Sep 2026).
     */
    public function test_failing_leaves_the_module_open_and_withholds_the_answers(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off']);
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);

        $graded = $this->submitAttempt()->submit(
            self::TRAINEE,
            self::TRAINING,
            $attempt->publicId,
            self::TRAINING_VERSION,
            ['q1' => 'a', 'q2' => 'a'],
            'key-fail',
        );

        self::assertFalse($graded->isPass);
        self::assertSame('a', $graded->questions[0]['selectedOptionKey'] ?? null);
        self::assertFalse($graded->questions[0]['isCorrect'] ?? null);
        foreach ($graded->questions as $question) {
            self::assertArrayNotHasKey('correctOptionKey', $question);
            self::assertArrayNotHasKey('correctReason', $question);
        }

        $detail = $this->training->findTraining(self::TRAINEE, self::TRAINING);
        self::assertNotNull($detail);
        self::assertFalse($detail->status->isFinal(), 'A failed attempt is re-sittable.');
    }

    public function test_an_attempt_cannot_be_submitted_twice(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off']);
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);
        $this->submitAttempt()->submit(
            self::TRAINEE,
            self::TRAINING,
            $attempt->publicId,
            self::TRAINING_VERSION,
            ['q1' => 'b', 'q2' => 'b'],
            'key-one',
        );

        $this->expectException(OnboardingWriteConflict::class);

        $this->submitAttempt()->submit(
            self::TRAINEE,
            self::TRAINING,
            $attempt->publicId,
            self::TRAINING_VERSION,
            ['q1' => 'a', 'q2' => 'a'],
            'key-two',
        );
    }

    public function test_a_submission_without_an_idempotency_key_is_refused_before_anything_is_written(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off']);
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);

        try {
            $this->submitAttempt()->submit(
                self::TRAINEE,
                self::TRAINING,
                $attempt->publicId,
                self::TRAINING_VERSION,
                ['q1' => 'b', 'q2' => 'b'],
                null,
            );
            self::fail('A submission with no idempotency key was accepted.');
        } catch (InvalidOnboardingValue) {
            $stored = $this->training->findAttempt(self::TRAINEE, self::TRAINING, $attempt->publicId);
            self::assertNotNull($stored);
            self::assertFalse($stored->isSubmitted(), 'Nothing was written.');
        }
    }

    // ---------------------------------------------------------------- policy

    public function test_acknowledging_stores_the_server_s_wording_and_completes_the_item(): void
    {
        $this->givenPolicy();

        $detail = $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-ack');

        self::assertTrue($detail->isAcknowledged());
        self::assertSame(AssignmentStatus::COMPLETED, $detail->status->toString());
        self::assertSame(
            ['I have read and accept the Site Safety policy.'],
            $this->policies->declarations,
            'The declaration is the policy version\'s, never the request\'s.',
        );
        self::assertSame(StatusActor::SYSTEM, $this->policies->transitions[0]->actor->toString());
    }

    /**
     * A handbook policy that is read, not accepted. The same request completes
     * it — the version decides which it is, not the browser — and no
     * acknowledgement is written, because there was nothing to agree to.
     */
    public function test_a_read_only_policy_is_completed_by_marking_it_read_with_no_acknowledgement(): void
    {
        $this->givenPolicy(requiresAcknowledgement: false);

        $detail = $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-read');

        self::assertSame(AssignmentStatus::COMPLETED, $detail->status->toString());
        self::assertFalse($detail->isAcknowledged());
        self::assertSame([], $this->policies->declarations, 'Marking as read writes no acknowledgement.');
        self::assertCount(1, $this->policies->transitions);
        self::assertSame(StatusActor::SYSTEM, $this->policies->transitions[0]->actor->toString());
        self::assertSame('Marked the policy as read.', $this->policies->transitions[0]->note);
    }

    public function test_a_read_only_policy_cannot_be_marked_read_twice_but_a_double_click_replays(): void
    {
        $this->givenPolicy(requiresAcknowledgement: false);
        $service = $this->acknowledge();

        $first = $service->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'one-click');
        $replayed = $service->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'one-click');
        self::assertSame($first->status->toString(), $replayed->status->toString());
        self::assertCount(1, $this->policies->transitions, 'The replay wrote nothing.');

        $this->expectException(OnboardingWriteConflict::class);
        $service->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'second-click');
    }

    public function test_marking_a_superseded_version_read_is_refused(): void
    {
        $this->givenPolicy(requiresAcknowledgement: false);

        $this->expectException(ContentVersionSuperseded::class);

        $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, 'version-policy-1', 'key-old');
    }

    /**
     * 5.4, owner decision 24 Sep 2026: a signed policy's declaration is part of
     * acknowledging it. Without one nothing is written and the item stays open.
     */
    public function test_a_signed_policy_is_not_acknowledged_without_its_declaration(): void
    {
        $this->givenPolicy(declarationKind: PolicyDeclaration::SIGN_OFF, documentReference: 'HR-POL-001-V004');

        try {
            $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-unsigned');
            self::fail('A signed policy was acknowledged with no signature.');
        } catch (InvalidOnboardingValue) {
        }
        self::assertSame([], $this->policies->declarations, 'Nothing was written.');
        self::assertSame([], $this->policies->transitions);

        // The same key, now with the declaration, goes through: the refusal left nothing in the ledger.
        $detail = $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-unsigned', [
            'fullName' => 'Sam Starter',
            'signature' => SignatureFieldTest::png(400, 120),
            'accepted' => true,
        ]);

        self::assertTrue($detail->isAcknowledged());
        self::assertSame(AssignmentStatus::COMPLETED, $detail->status->toString());
        self::assertNotNull($detail->declaration);
        self::assertSame('Sam Starter', $detail->declaration->text('fullName'));
        $public = $detail->toArray()['declaration'];
        self::assertIsArray($public);
        self::assertTrue($public['signed']);
        self::assertArrayNotHasKey('signature', $public, 'The signature image never goes back to the browser.');
    }

    public function test_a_declaration_sent_for_a_policy_that_asks_for_none_is_ignored(): void
    {
        $this->givenPolicy();

        $detail = $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-extra', ['fullName' => 'Sam']);

        self::assertTrue($detail->isAcknowledged());
        self::assertNull($detail->declaration);
    }

    public function test_a_second_acknowledgement_of_the_same_version_is_refused(): void
    {
        $this->givenPolicy();
        $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-ack');

        $this->expectException(OnboardingWriteConflict::class);

        $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-ack-2');
    }

    /**
     * The double-clicked button. The same key with the same request replays the
     * first result rather than producing a second acknowledgement or a 409 the
     * person cannot act on.
     */
    public function test_the_same_key_twice_replays_rather_than_writing_again(): void
    {
        $this->givenPolicy();
        $service = $this->acknowledge();

        $first = $service->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'one-click');
        $second = $service->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'one-click');

        self::assertSame($first->acknowledgedAtUtc, $second->acknowledgedAtUtc);
        self::assertCount(1, $this->policies->declarations, 'Exactly one acknowledgement was written.');
    }

    public function test_the_same_key_for_a_different_request_is_a_conflict(): void
    {
        $this->givenPolicy();
        $this->givenPolicy(assignmentPublicId: 'assignment-policy-2');
        $service = $this->acknowledge();

        $service->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'shared-key');

        $this->expectException(OnboardingWriteConflict::class);

        $service->acknowledge(self::TRAINEE, 'assignment-policy-2', self::POLICY_VERSION, 'shared-key');
    }

    public function test_a_completed_acquisition_records_the_id_of_what_it_produced(): void
    {
        $this->givenPolicy();
        $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-ack');

        $ledgerRow = $this->ledger->find(
            OnboardingIdempotentWrite::actorKey(self::TRAINEE),
            'onboarding.acknowledgePolicy',
            'key-ack',
        );

        self::assertNotNull($ledgerRow);
        self::assertTrue($ledgerRow->isCompleted());
        self::assertSame(self::POLICY, $ledgerRow->resultPublicId);
    }

    // ------------------------------------------------------------------ form

    public function test_a_first_draft_needs_no_token_and_a_later_one_does(): void
    {
        $this->givenForm();

        $saved = $this->saveDraft()->save(self::TRAINEE, self::FORM, self::FORM_VERSION, ['name' => 'Sam'], null);
        self::assertNotNull($saved->draftRowVersion);

        $this->expectException(OnboardingWriteConflict::class);

        $this->saveDraft()->save(self::TRAINEE, self::FORM, self::FORM_VERSION, ['name' => 'Someone else'], null);
    }

    public function test_a_stale_token_loses_and_the_first_change_stands(): void
    {
        $this->givenForm();
        $first = $this->saveDraft()->save(self::TRAINEE, self::FORM, self::FORM_VERSION, ['name' => 'Sam'], null);
        self::assertNotNull($first->draftRowVersion);
        $stale = RowVersionToken::fromBase64($first->draftRowVersion);

        $this->saveDraft()->save(self::TRAINEE, self::FORM, self::FORM_VERSION, ['name' => 'Sam Two'], $stale);

        $this->expectException(OnboardingWriteConflict::class);

        $this->saveDraft()->save(self::TRAINEE, self::FORM, self::FORM_VERSION, ['name' => 'Sam Three'], $stale);
    }

    /**
     * The property the whole split exists for: nothing classified payroll or
     * restricted may appear in the ordinary half, which is the one the workflow
     * schema holds and reports read.
     */
    public function test_a_submitted_payload_is_split_before_anything_is_written(): void
    {
        $this->givenForm();

        $this->submitForm()->submit(
            self::TRAINEE,
            self::FORM,
            self::FORM_VERSION,
            [
                'preferredName' => 'Sam',
                'bank' => ['accountNumber' => '000-000'],
                'health' => ['conditions' => 'none'],
            ],
            'key-form',
        );

        $submission = $this->forms->submissions[0];
        self::assertSame(['preferredName' => 'Sam'], $submission['ordinary']);
        self::assertSame(['bank.accountNumber' => '000-000'], $submission['payroll']);
        self::assertSame(['health.conditions' => 'none'], $submission['restricted']);

        $ordinary = json_encode($submission['ordinary'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('000-000', $ordinary);
        self::assertStringNotContainsString('conditions', $ordinary);
    }

    public function test_an_unclassified_field_refuses_the_whole_submission(): void
    {
        $this->givenForm();

        $this->expectException(FormVersionNotClassified::class);

        $this->submitForm()->submit(
            self::TRAINEE,
            self::FORM,
            self::FORM_VERSION,
            ['preferredName' => 'Sam', 'somethingNobodyClassified' => 'x'],
            'key-form',
        );
    }

    /**
     * The schema is checked on the server, before anything is written — the
     * browser's checks are a convenience a request can skip.
     */
    public function test_a_submission_the_schema_refuses_writes_nothing(): void
    {
        $this->givenForm();

        try {
            $this->submitForm()->submit(self::TRAINEE, self::FORM, self::FORM_VERSION, ['preferredName' => ''], 'key-form');
            self::fail('An empty required field was accepted.');
        } catch (InvalidOnboardingValue $refused) {
            self::assertStringContainsString('Preferred name', $refused->getMessage());
        }

        self::assertSame([], $this->forms->submissions);
        self::assertSame([], $this->forms->transitions);
    }

    /**
     * Invariant 7. Handing a form in is not finishing it — somebody has to read
     * it, and the person who filled it in is not that somebody.
     */
    public function test_submitting_a_form_reaches_submitted_and_no_further(): void
    {
        $this->givenForm();

        $detail = $this->submitForm()->submit(
            self::TRAINEE,
            self::FORM,
            self::FORM_VERSION,
            ['preferredName' => 'Sam'],
            'key-form',
        );

        self::assertSame(AssignmentStatus::SUBMITTED, $detail->status->toString());
        self::assertSame(1, $detail->submittedRevisionCount);
        self::assertNull($detail->draftRowVersion, 'Handing in throws the draft away.');
        self::assertSame(StatusActor::TRAINEE, $this->forms->transitions[0]->actor->toString());
    }

    /**
     * The ledger row is read by people debugging other people's inductions, so
     * the fingerprint is a hash of the payload rather than the payload.
     */
    public function test_the_ledger_never_holds_the_submitted_values(): void
    {
        $this->givenForm();
        $this->submitForm()->submit(
            self::TRAINEE,
            self::FORM,
            self::FORM_VERSION,
            ['preferredName' => 'Sam', 'bank' => ['accountNumber' => '000-000']],
            'key-form',
        );

        $row = $this->ledger->find(
            OnboardingIdempotentWrite::actorKey(self::TRAINEE),
            'onboarding.submitForm',
            'key-form',
        );

        self::assertNotNull($row);
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('000-000', $encoded);
        self::assertStringNotContainsString('Sam', $encoded);
    }

    // ------------------------------------------------------------- documents

    public function test_somebody_with_no_induction_gets_an_empty_document_list_rather_than_a_refusal(): void
    {
        $service = new ListOwnDocumentsService($this->documents, $this->gate());

        self::assertSame([], $service->forUser(999));
    }

    public function test_opening_a_restricted_document_streams_it_and_writes_an_audit_row(): void
    {
        $this->documents->withDocument(self::TRAINEE, self::document(DataClass::payroll()));

        $access = $this->openDocument()->open(self::TRAINEE, 'doc-1');

        self::assertSame(DocumentDeliveryPolicy::STREAM, $access->mode);
        self::assertCount(1, $this->documents->accessLog);
        self::assertSame('ONBOARDING.SELF.READ', $this->documents->accessLog[0]['permissionCode']);
        self::assertSame(self::TRAINEE, $this->documents->accessLog[0]['actorUserId']);
    }

    public function test_an_ordinary_document_is_streamed_too(): void
    {
        $this->documents->withDocument(self::TRAINEE, self::document(DataClass::ordinaryHr()));

        self::assertSame(DocumentDeliveryPolicy::STREAM, $this->openDocument()->open(self::TRAINEE, 'doc-1')->mode);
    }

    public function test_another_person_s_document_is_not_found_and_is_not_audited(): void
    {
        $this->documents->withDocument(self::SOMEBODY_ELSE, self::document(DataClass::payroll()));

        try {
            $this->openDocument()->open(self::TRAINEE, 'doc-1');
            self::fail('Another person\'s document was opened.');
        } catch (OnboardingDocumentNotFound) {
            self::assertSame([], $this->documents->accessLog, 'A refused read is not a read.');
        }
    }

    public function test_a_download_filename_is_hr_s_filing_name_and_never_the_stored_path(): void
    {
        $ref = new OnboardingDocumentRef(
            publicId: 'doc-1',
            ownerUserId: self::TRAINEE,
            documentKind: 'employee_details',
            dataClass: DataClass::payroll(),
            blobContainer: 'onboarding',
            blobPath: '../../etc/passwd',
            contentType: 'application/pdf',
            byteSize: 10,
            contentSha256: str_repeat('a', 64),
            generatedAtUtc: '2026-09-22T00:00:00+00:00',
            personName: 'Sam Lee',
            documentTitle: 'Employee Details Form',
            completedAtUtc: '2026-09-21T23:30:00Z',
        );

        self::assertSame('26.09.22 LEE Sam - Employee Details Form.pdf', $ref->downloadFilename());
        self::assertStringNotContainsString('passwd', $ref->contentDisposition());
    }

    // ---------------------------------------------------------- leaver window

    public function test_a_leaver_inside_the_window_may_read_documents_but_not_submit(): void
    {
        $this->records->withRecord(self::TRAINEE, new OnboardingTraineeRecord(
            'trainee-1',
            '2026-01-01',
            new DateTimeImmutable('2026-09-20 17:00:00', new DateTimeZone('UTC')),
        ));
        $this->givenPolicy();
        $this->documents->withDocument(self::TRAINEE, self::document(DataClass::ordinaryHr()));

        self::assertSame(DocumentDeliveryPolicy::STREAM, $this->openDocument()->open(self::TRAINEE, 'doc-1')->mode);

        $this->expectException(OnboardingSubmissionWindowClosed::class);

        $this->acknowledge()->acknowledge(self::TRAINEE, self::POLICY, self::POLICY_VERSION, 'key-ack');
    }

    public function test_a_leaver_past_the_window_reaches_nothing(): void
    {
        $this->records->withRecord(self::TRAINEE, new OnboardingTraineeRecord(
            'trainee-1',
            '2020-01-01',
            new DateTimeImmutable('2020-01-01 09:00:00', new DateTimeZone('UTC')),
        ));
        $this->documents->withDocument(self::TRAINEE, self::document(DataClass::ordinaryHr()));

        $this->expectException(OnboardingAccessWindowClosed::class);

        $this->openDocument()->open(self::TRAINEE, 'doc-1');
    }

    // ----------------------------------------------------------------- setup

    /**
     * @param list<string>             $reached
     * @param list<AssessmentQuestion> $questions
     */
    private function givenTraining(
        int $userId = self::TRAINEE,
        array $reached = [],
        ?array $questions = null,
        string $moduleSlug = 'site-induction',
    ): void {
        $questions ??= [
            AssessmentQuestion::of('q1', 1, 'First', ['a' => 'No', 'b' => 'Yes'], 'b', 'Because yes.'),
            AssessmentQuestion::of('q2', 2, 'Second', ['a' => 'No', 'b' => 'Yes'], 'b'),
        ];

        $this->training->withTraining(
            $userId,
            new OnboardingTrainingDetail(
                assignmentPublicId: self::TRAINING,
                title: 'Site induction',
                summary: null,
                versionPublicId: self::TRAINING_VERSION,
                versionNumber: 3,
                status: AssignmentStatus::notStarted(),
                manifest: PresentationManifest::fromArray(['intro', 'hazards', 'sign-off']),
                requiredSlideCount: 0,
                passMarkPercent: 80,
                questionCount: count($questions),
                reachedSlideIds: $reached,
                latestAttempt: null,
                moduleSlug: $moduleSlug,
            ),
            $questions,
        );
    }

    private function givenPolicy(
        string $assignmentPublicId = self::POLICY,
        bool $requiresAcknowledgement = true,
        ?string $declarationKind = null,
        ?string $documentReference = null,
    ): void {
        $this->policies->withPolicy(self::TRAINEE, new OnboardingPolicyDetail(
            assignmentPublicId: $assignmentPublicId,
            title: 'Site Safety',
            versionPublicId: self::POLICY_VERSION,
            versionNumber: 2,
            status: AssignmentStatus::notStarted(),
            contentBlocks: [['type' => 'paragraph', 'text' => 'Wear the boots.']],
            declarationText: 'I have read and accept the Site Safety policy.',
            effectiveFrom: '2026-01-01',
            acknowledgedAtUtc: null,
            requiresAcknowledgement: $requiresAcknowledgement,
            documentReference: $documentReference,
            declarationKind: $declarationKind,
        ));
    }

    private function givenForm(): void
    {
        $this->forms->withForm(self::TRAINEE, new OnboardingFormDetail(
            assignmentPublicId: self::FORM,
            title: 'Employee details',
            versionPublicId: self::FORM_VERSION,
            versionNumber: 1,
            status: AssignmentStatus::notStarted(),
            schema: ['fields' => [
                ['path' => 'preferredName', 'label' => 'Preferred name', 'type' => 'text', 'required' => true],
                ['path' => 'bank.accountNumber', 'label' => 'Account number', 'type' => 'text', 'required' => false],
                ['path' => 'health.conditions', 'label' => 'Conditions', 'type' => 'textarea', 'required' => false],
            ]],
            draftData: [],
            draftRowVersion: null,
            draftUpdatedAtUtc: null,
            submittedRevisionCount: 0,
            lastSubmittedAtUtc: null,
        ));
        $this->forms->withClassifications(self::FORM_VERSION, [
            'preferredName' => DataClass::ORDINARY_HR,
            'bank.accountNumber' => DataClass::PAYROLL,
            'health.conditions' => DataClass::RESTRICTED_HR,
        ]);
    }

    private static function document(DataClass $class): OnboardingDocumentRef
    {
        return new OnboardingDocumentRef(
            publicId: 'doc-1',
            ownerUserId: self::TRAINEE,
            documentKind: 'employee_details',
            dataClass: $class,
            blobContainer: 'onboarding',
            blobPath: 'synthetic/doc-1.pdf',
            contentType: 'application/pdf',
            byteSize: 1024,
            contentSha256: str_repeat('b', 64),
            generatedAtUtc: '2026-09-22T00:00:00+00:00',
        );
    }

    private function gate(): OnboardingSubmissionGate
    {
        return new OnboardingSubmissionGate($this->records, $this->clock);
    }

    private function idempotency(): OnboardingIdempotentWrite
    {
        return new OnboardingIdempotentWrite($this->ledger, $this->clock, new UuidV4Generator());
    }

    private function progress(): RecordPresentationProgressService
    {
        return new RecordPresentationProgressService($this->training, $this->gate());
    }

    private function startAttempt(): StartAssessmentAttemptService
    {
        return new StartAssessmentAttemptService($this->training, $this->gate());
    }

    private function submitAttempt(): SubmitAssessmentAttemptService
    {
        return new SubmitAssessmentAttemptService($this->training, $this->gate(), $this->idempotency());
    }

    private function acknowledge(): AcknowledgePolicyService
    {
        return new AcknowledgePolicyService($this->policies, $this->gate(), $this->idempotency());
    }

    private function saveDraft(): SaveFormDraftService
    {
        return new SaveFormDraftService($this->forms, $this->gate());
    }

    private function submitForm(): SubmitFormService
    {
        return new SubmitFormService($this->forms, $this->gate(), $this->idempotency());
    }

    private function openDocument(): OpenOwnDocumentService
    {
        return new OpenOwnDocumentService($this->documents, $this->gate());
    }

    // ------------------------------------------------ paper copies (5.4)

    public function test_a_submitted_attempt_gets_its_paper_sheet_once_with_the_answers_in_order(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off']);
        [$generation, $store, $renderer] = $this->paperCopies();
        $generation->withAssessment(self::TRAINING, new AssessmentDocumentContext(9, self::TRAINEE, 'corporate-whse-induction', 'Corporate and WHSE Induction', 'Sam Synthetic', 'Storeperson', 'Warehouse', 'Dandenong', 'Morgan Manager'));
        $submit = new SubmitAssessmentAttemptService($this->training, $this->gate(), $this->idempotency(), new GenerateAssessmentDocumentService($generation, $this->training, $renderer, $store));
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);

        $submit->submit(self::TRAINEE, self::TRAINING, $attempt->publicId, self::TRAINING_VERSION, ['q1' => 'b', 'q2' => 'a'], 'key-sheet');
        $submit->submit(self::TRAINEE, self::TRAINING, $attempt->publicId, self::TRAINING_VERSION, ['q1' => 'b', 'q2' => 'a'], 'key-sheet');

        self::assertCount(1, $store->objects, 'a replay makes no second sheet');
        self::assertCount(1, $renderer->inputs);
        $input = $renderer->inputs[0];
        self::assertSame(['b', 'a'], array_column($input->questions, 'chosenKey'), 'the attempt\'s own order');
        self::assertSame(50, $input->scorePercent);
        self::assertFalse($input->passed);
        $listed = $this->documents->listDocuments(self::TRAINEE);
        self::assertCount(1, $listed);
        self::assertSame('assessment', $listed[0]->documentKind);
        self::assertSame(DataClass::WORKFLOW, $listed[0]->dataClass->toInt());
    }

    // ------------------------------------------------ WHSE sign-off (5.4)

    public function test_passing_the_whse_induction_hands_it_to_hr_instead_of_completing_it(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off'], moduleSlug: 'corporate-whse-induction');
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);

        $this->submitAttempt()->submit(self::TRAINEE, self::TRAINING, $attempt->publicId, self::TRAINING_VERSION, ['q1' => 'b', 'q2' => 'b'], 'key-whse');

        $handedIn = end($this->training->transitions);
        self::assertNotFalse($handedIn);
        self::assertSame(AssignmentStatus::SUBMITTED, $handedIn->to->toString());
        self::assertSame(StatusActor::TRAINEE, $handedIn->actor->toString(), 'the person hands it in; the system never submits');
        $detail = $this->training->findTraining(self::TRAINEE, self::TRAINING);
        self::assertNotNull($detail);
        self::assertFalse($detail->mayStartAttempt(), 'no re-sit while HR has the passed attempt');
    }

    public function test_hr_accepts_the_whse_induction_only_with_feedback_and_the_sheet_is_signed_off(): void
    {
        $this->givenTraining(reached: ['intro', 'hazards', 'sign-off'], moduleSlug: 'corporate-whse-induction');
        [$generation, $store, $renderer] = $this->paperCopies();
        $generation->withAssessment(self::TRAINING, new AssessmentDocumentContext(9, self::TRAINEE, 'corporate-whse-induction', 'Corporate and WHSE Induction', 'Sam Synthetic', 'Storeperson', 'Warehouse', 'Dandenong', 'Morgan Manager'));
        $generation->displayNames[40] = 'Hana Reviewer';
        $sheets = new GenerateAssessmentDocumentService($generation, $this->training, $renderer, $store);
        $attempt = $this->startAttempt()->start(self::TRAINEE, self::TRAINING, self::TRAINING_VERSION);
        (new SubmitAssessmentAttemptService($this->training, $this->gate(), $this->idempotency(), $sheets))
            ->submit(self::TRAINEE, self::TRAINING, $attempt->publicId, self::TRAINING_VERSION, ['q1' => 'b', 'q2' => 'b'], 'key-whse');

        $token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x0B\xB8");
        $reviews = new InMemoryOnboardingReviewRepository();
        $reviews->withItem(
            new ReviewItemRecord(9, self::TRAINING, self::TRAINEE, 'training', 'item-whse', 'Corporate and WHSE Induction', AssignmentStatus::submitted(), $token, null, 'corporate-whse-induction'),
            new ReviewQueueEntry(self::TRAINING, self::TRAINEE, 'person-601', 'Sam Synthetic', 'training', 'item-whse', 'Corporate and WHSE Induction', 1, AssignmentStatus::submitted(), '2026-09-22T00:05:00Z', null, null),
        );
        $outcomes = $reviews->reviewDetail(self::TRAINING)?->toArray(40)['outcomes'] ?? [];
        self::assertIsArray($outcomes);
        self::assertSame([true, true], array_column($outcomes, 'requiresNote'), 'accepting WHSE asks for the feedback');
        $decide = new DecideReviewService($reviews, new InMemoryOnboardingAdminAuditLog(), null, null, $sheets);
        $hr = new ActorContext(40, 'hr-public-id', 'hr@example.test');

        try {
            $decide->decide($hr, self::TRAINING, $token, AssignmentStatus::completed(), '  ');
            self::fail('accepted WHSE without feedback');
        } catch (InvalidOnboardingValue) {
        }
        self::assertCount(1, $renderer->inputs, 'no sheet for a refused acceptance');

        $decided = $decide->decide($hr, self::TRAINING, $token, AssignmentStatus::completed(), 'Safe around the forklift bay.');

        self::assertSame(AssignmentStatus::COMPLETED, $decided->status()->toString());
        self::assertCount(2, $renderer->inputs);
        self::assertNull($renderer->inputs[0]->signOff, 'the copy made at submission is left as it was');
        $signOff = $renderer->inputs[1]->signOff;
        self::assertNotNull($signOff);
        self::assertSame('Safe around the forklift bay.', $signOff->feedback);
        self::assertSame('Hana Reviewer', $signOff->receivedBy);
        self::assertSame((new DateTimeImmutable('now', new DateTimeZone('Australia/Melbourne')))->format('Y-m-d'), $signOff->reviewedOn);
        self::assertTrue($renderer->inputs[1]->passed);
        self::assertCount(2, $this->documents->listDocuments(self::TRAINEE));
        self::assertArrayHasKey(sprintf('%d/attempt-%s-signed-off.pdf', self::TRAINEE, $attempt->publicId), $store->objects);
    }

    public function test_a_submitted_form_gets_its_paper_copy(): void
    {
        $this->givenForm();
        [$generation, $store] = $this->paperCopies();
        $generation->withSubmission(self::FORM, new FormSubmissionForDocument(31, 9, self::TRAINEE, 'employee-details', 'payroll', 1, '2026-09-22T09:00:00Z', 'Sam Synthetic'));
        $renderer = new class () implements OnboardingFormDocumentRenderer {
            /** @var list<array<string, mixed>> */
            public array $answers = [];

            public function render(FormSubmissionForDocument $submission, array $answers, string $moment = self::AT_SUBMISSION): ?RenderedDocument
            {
                $this->answers[] = $answers;

                return new RenderedDocument('employee_details', 'application/pdf', '%PDF-1.4 form');
            }
        };
        $submit = new SubmitFormService($this->forms, $this->gate(), $this->idempotency(), new GenerateFormSubmissionDocumentService($generation, $renderer, $store, new UuidV4Generator()));

        $submit->submit(self::TRAINEE, self::FORM, self::FORM_VERSION, ['preferredName' => 'Sam', 'bank' => ['accountNumber' => '12345678']], 'key-form-copy');

        self::assertSame([['preferredName' => 'Sam', 'bank' => ['accountNumber' => '12345678']]], $renderer->answers, 'the whole payload, before the split');
        $listed = $this->documents->listDocuments(self::TRAINEE);
        self::assertCount(1, $listed);
        self::assertSame(DataClass::PAYROLL, $listed[0]->dataClass->toInt());
    }

    /**
     * @return array{InMemoryOnboardingDocumentGenerationRepository, InMemoryOnboardingDocumentStore, OnboardingAssessmentDocumentRenderer&object{inputs: list<AssessmentDocumentInput>}}
     */
    private function paperCopies(): array
    {
        $renderer = new class () implements OnboardingAssessmentDocumentRenderer {
            /** @var list<AssessmentDocumentInput> */
            public array $inputs = [];

            public function render(AssessmentDocumentInput $input): ?RenderedDocument
            {
                $this->inputs[] = $input;

                return new RenderedDocument('assessment', 'application/pdf', '%PDF-1.4 sheet');
            }
        };

        return [new InMemoryOnboardingDocumentGenerationRepository($this->documents), new InMemoryOnboardingDocumentStore(), $renderer];
    }
}
