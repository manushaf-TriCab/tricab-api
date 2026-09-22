<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * "I have read this policy and I accept it."
 *
 * ## The three things a client does not get to say
 *
 *  1. **Which version.** It echoes back the version it read, and that value goes
 *     into the `WHERE` clause. It cannot name a different one, and a version
 *     superseded since the page loaded matches nothing — see A6: an
 *     acknowledgement stays bound to the wording actually on screen.
 *  2. **The declaration wording.** Taken from the policy version by the server
 *     and stored verbatim. A browser that posted "I have skimmed this" would
 *     otherwise write exactly that into the evidence.
 *  3. **Whether it counts.** Acknowledging completes the item, by
 *     {@see \TriCab\Core\Onboarding\Domain\ValueObject\StatusActor::SYSTEM}, and
 *     there is no parameter to decline that or to set any other status.
 *
 * ## Completing on the person's own action, and why that is not invariant 7
 *
 * Invariant 7 says a trainee cannot mark their own induction complete.
 * Acknowledging a policy completes the *item*, and the distinction is real: for
 * a form or an assessment there is work whose quality somebody has to judge, and
 * the person who did it is not that somebody. For a policy there is nothing to
 * judge. The whole obligation is "read this and say you accept it", the
 * acknowledgement row IS the evidence, and a reviewer adding a tick to it would
 * be attesting to something they cannot observe.
 *
 * ## Once
 *
 * `UQ_onboarding_PolicyAcknowledgements_UserVersion` is the database's answer,
 * the ledger is this layer's, and they are both wanted: the constraint holds when
 * somebody writes a script, and the ledger lets a double-clicked button be
 * answered with the first result rather than a 409 the person cannot act on.
 */
final readonly class AcknowledgePolicyService
{
    public function __construct(
        private OnboardingPolicyRepository $policies,
        private OnboardingSubmissionGate $gate,
        private OnboardingIdempotentWrite $idempotency,
    ) {
    }

    /**
     * @throws OnboardingAssignmentNotFound
     * @throws ContentVersionSuperseded
     * @throws OnboardingWriteConflict
     */
    public function acknowledge(
        int $userId,
        string $assignmentPublicId,
        string $versionPublicId,
        ?string $idempotencyKey,
    ): OnboardingPolicyDetail {
        $this->gate->assertMaySubmit($userId);

        $detail = $this->policies->findPolicy($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $versionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        $declarationText = $detail->declarationText;

        return $this->idempotency->run(
            $userId,
            OnboardingWriteIdempotencyStore::OPERATION_ACKNOWLEDGE_POLICY,
            $idempotencyKey,
            ['assignment' => $assignmentPublicId, 'version' => $versionPublicId],
            // The "already acknowledged" refusal lives INSIDE the write and not
            // before the ledger. Checked first, a double-clicked button would hit
            // it on the second click and be told its own successful acknowledgement
            // was a conflict — the ledger has to get the chance to recognise the
            // replay before anything decides the state is wrong.
            function () use ($userId, $assignmentPublicId, $versionPublicId, $declarationText, $idempotencyKey, $detail): array {
                if ($detail->isAcknowledged()) {
                    throw OnboardingWriteConflict::alreadyRecorded('acknowledgement');
                }

                $acknowledged = $this->policies->acknowledge(
                    $userId,
                    $assignmentPublicId,
                    $versionPublicId,
                    $declarationText,
                    $idempotencyKey,
                    StatusTransition::systemMovesTo(
                        $detail->status,
                        AssignmentStatus::completed(),
                        'Acknowledged the policy.',
                    ),
                );

                return [$acknowledged, $acknowledged->assignmentPublicId];
            },
            function () use ($userId, $assignmentPublicId): OnboardingPolicyDetail {
                $replayed = $this->policies->findPolicy($userId, $assignmentPublicId);

                if ($replayed === null) {
                    throw new \RuntimeException('The idempotency ledger names a policy that is no longer allocated.');
                }

                return $replayed;
            },
        );
    }
}
