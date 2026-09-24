<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\SplitFormPayload;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;
use TriCab\Core\Onboarding\Domain\Service\FormSubmissionRules;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * Hands a form in.
 *
 * ## The payload is taken apart before anything is written
 *
 * {@see SplitFormPayload::fromPayload()} runs first, and it fails closed: a
 * field with no classification refuses the whole submission rather than
 * defaulting to ordinary. That is deliberately inconvenient. The alternative —
 * accept it, write it to the workflow schema, sort it out later — means the bank
 * account is already in every backup taken since, and every query that forgot to
 * filter. Refusing produces a submission somebody has to fix; defaulting
 * produces a disclosure nobody notices.
 *
 * `[onboarding_hr].[FieldClassifications]` is the map, and an unclassified form
 * version therefore cannot be submitted at all. That is the same rule
 * {@see \TriCab\Core\Onboarding\Domain\Service\FormVersionPublishGuard} applies
 * at publish time, restated here because the two can drift: a version published
 * while its access group was `hr` and later changed has unclassified fields and
 * a live allocation.
 *
 * ## `submitted`, not `completed`
 *
 * Work order invariant 7. Somebody has to read the form, and the person who
 * filled it in is not that somebody. Phase 4's reviewer queue moves it the rest
 * of the way — or sends it back as `fix_and_resubmit`, which is why a second
 * submission is a revision rather than an error.
 *
 * ## A correction is revision 2
 *
 * `TR_FormSubmissions_Immutable` refuses an UPDATE at the database, so the only
 * way to change a submitted form is to submit another one. The revision number
 * is allocated inside the write's own transaction; a caller choosing it would be
 * racing every other caller for the same assignment.
 */
final readonly class SubmitFormService
{
    public function __construct(
        private OnboardingFormRepository $forms,
        private OnboardingSubmissionGate $gate,
        private OnboardingIdempotentWrite $idempotency,
        /** Phase 5.4: the paper copy, made after the submission commits. Null in tests that do not need it. */
        private ?GenerateFormSubmissionDocumentService $documents = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws OnboardingAssignmentNotFound
     * @throws ContentVersionSuperseded
     * @throws OnboardingWriteConflict
     * @throws \TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified
     * @throws \TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue an answer the schema refuses
     */
    public function submit(
        int $userId,
        string $assignmentPublicId,
        string $versionPublicId,
        array $payload,
        ?string $idempotencyKey,
    ): OnboardingFormDetail {
        $this->gate->assertMaySubmit($userId);

        $detail = $this->forms->findForm($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $versionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        if ($detail->status->isFinal()) {
            // A completed form is closed. Resubmitting would write a revision
            // against work a reviewer has already accepted, and the acceptance
            // would then refer to wording nobody re-read.
            throw OnboardingWriteConflict::alreadyRecorded('form submission');
        }

        $split = SplitFormPayload::fromPayload(
            $payload,
            $this->forms->fieldClassifications($detail->versionPublicId),
        );

        // Then every answer against the version's own schema, still before
        // anything is written: a submission is immutable, so a bad value
        // accepted here is one a reviewer has to send back.
        FormSubmissionRules::check($detail->schema, FormPayloadSplitter::flatten($payload));

        $result = $this->idempotency->run(
            $userId,
            OnboardingWriteIdempotencyStore::OPERATION_SUBMIT_FORM,
            $idempotencyKey,
            // The payload's own hash, not the payload. A request fingerprint is
            // written to a ledger row that people read while debugging, and a
            // ledger holding a tax file number defeats the point of splitting
            // the submission in the first place.
            [
                'assignment' => $assignmentPublicId,
                'version' => $versionPublicId,
                'payload' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ],
            function () use ($userId, $assignmentPublicId, $versionPublicId, $split, $idempotencyKey, $detail): array {
                $submitted = $this->forms->submitForm(
                    $userId,
                    $assignmentPublicId,
                    $versionPublicId,
                    $split,
                    $idempotencyKey,
                    StatusTransition::traineeMovesTo(
                        $detail->status,
                        AssignmentStatus::submitted(),
                        $userId,
                        'Submitted the form.',
                    ),
                );

                return [$submitted, $submitted->assignmentPublicId];
            },
            function () use ($userId, $assignmentPublicId): OnboardingFormDetail {
                $replayed = $this->forms->findForm($userId, $assignmentPublicId);

                if ($replayed === null) {
                    throw new \RuntimeException('The idempotency ledger names a form that is no longer allocated.');
                }

                return $replayed;
            },
        );

        // Outside the write, and on a replay too: generation is once per
        // revision, so a replay that finds the copy already made is a no-op,
        // and one whose first attempt failed gets a second chance.
        $this->documents?->generate($userId, $assignmentPublicId, $payload);

        return $result;
    }
}
