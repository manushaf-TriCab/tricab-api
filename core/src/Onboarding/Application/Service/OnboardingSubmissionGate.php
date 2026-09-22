<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use DateTimeImmutable;
use LogicException;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingSubmissionWindowClosed;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Shared\Time\Clock;

/**
 * The leaver windows, applied to every self-service write and to the document
 * reads.
 *
 * A collaborator rather than a base class or a trait: there are seven services
 * that need it and they have nothing else in common, so sharing this through
 * inheritance would put six unrelated behaviours within reach of each other to
 * save one constructor argument.
 *
 * ## It decides one thing only
 *
 * Whether this person's *time* still permits the action.
 * {@see LeaverPolicy} splits reading from writing — fourteen days of read access
 * after departure, no submitting from the departure instant — and this class is
 * where that split is applied. Two exceptions rather than one flag, because the
 * SPA does different things with them: one takes the induction off the screen,
 * the other leaves it there, read-only.
 *
 * **Not** whether the item exists, and **not** whether it is theirs. Somebody
 * with no induction record at all passes cleanly here, because a person who has
 * never left has no window to be outside of. Ownership is settled a line later,
 * when the repository looks for the assignment and does not find it — which is
 * the same 404 a mistyped id gets, and is what work order invariant 4 asks for.
 * Refusing here instead would mean the gate had to answer "not found" for a
 * question about time, and the documents list would then 404 for every ordinary
 * employee rather than returning nothing.
 */
final readonly class OnboardingSubmissionGate
{
    public function __construct(
        private OnboardingSelfServiceRepository $records,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OnboardingAccessWindowClosed     when a leaver's read window has run out
     * @throws OnboardingSubmissionWindowClosed when they have left and may no longer submit
     */
    public function assertMaySubmit(int $userId): void
    {
        $departedAtUtc = $this->assertMayRead($userId);

        if (!LeaverPolicy::maySubmit($departedAtUtc, $this->clock->now())) {
            throw OnboardingSubmissionWindowClosed::afterDeparture();
        }
    }

    /**
     * @return DateTimeImmutable|null the recorded departure, for a caller that
     *                                needs it; null when they have not left, and
     *                                null when they have no induction record
     *
     * @throws OnboardingAccessWindowClosed
     */
    public function assertMayRead(int $userId): ?DateTimeImmutable
    {
        $record = $this->records->findTraineeRecord($userId);

        if ($record === null) {
            return null;
        }

        $departedAtUtc = $record->departedAtUtc;

        if (!LeaverPolicy::mayReadOwnRecords($departedAtUtc, $this->clock->now())) {
            // hasWindowClosed() is only true when a departure is recorded and in
            // the past, so the window end is computable here.
            if ($departedAtUtc === null) {
                throw new LogicException('LeaverPolicy refused read access without a recorded departure.');
            }

            throw OnboardingAccessWindowClosed::endedAt(LeaverPolicy::accessEndsAt($departedAtUtc));
        }

        return $departedAtUtc;
    }
}
