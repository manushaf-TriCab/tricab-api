<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * One person's allocated policies, and the acknowledgements they have given.
 *
 * ## Bound to the version, never to the policy
 *
 * `onboarding.PolicyAcknowledgements.PolicyVersionId` is what an acknowledgement
 * points at, and invariant A6 depends on it staying that way: superseding a
 * policy writes a new version row and moves no acknowledgement, so every record
 * keeps pointing at the wording that was actually on screen. An implementation
 * that resolved the current version at write time — rather than using the one
 * the caller read and echoed back — would quietly rewrite history for anybody
 * whose tab was open across a publish.
 *
 * ## The declaration text comes from the server
 *
 * `$declarationText` is passed in by the application service, which took it from
 * the policy version. It is never a request field. An acknowledgement is
 * evidence, and evidence whose wording the signatory chose is not evidence.
 */
interface OnboardingPolicyRepository
{
    /**
     * Null when the assignment is not this person's, is not a policy, or has
     * been withdrawn — indistinguishable on purpose (invariant 4).
     */
    public function findPolicy(int $userId, string $assignmentPublicId): ?OnboardingPolicyDetail;

    /**
     * Records the acknowledgement and completes the item, in one unit of work.
     *
     * A policy is the one item type a trainee's own action finishes outright:
     * there is nothing to mark and nobody to review, so `$transition` moves it
     * to `completed` under {@see \TriCab\Core\Onboarding\Domain\ValueObject\StatusActor::SYSTEM}.
     * That is not a loophole in work order invariant 7 — the person did not
     * decide the work was acceptable, they performed the whole of it, and the
     * evidence is the acknowledgement row itself.
     *
     * `UQ_onboarding_PolicyAcknowledgements_UserVersion` means a second
     * acknowledgement of the same version cannot be written. An implementation
     * translates that into {@see \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict::alreadyRecorded()}
     * rather than letting a unique-constraint message reach a browser.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict
     */
    public function acknowledge(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        string $declarationText,
        ?string $idempotencyKey,
        ?StatusTransition $transition,
    ): OnboardingPolicyDetail;
}
