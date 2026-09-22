<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Application\DTO\OnboardingSelfSummary;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed;
use TriCab\Core\Onboarding\Domain\Service\AssignmentProgress;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;
use TriCab\Core\Onboarding\Domain\Service\WorklistOrder;
use TriCab\Core\Shared\Time\Clock;

/**
 * Builds one person's own onboarding summary.
 *
 * Takes a `$userId` and nothing else. There is no overload accepting somebody
 * else's id, no `$actingAs`, and no flag that widens it — the only way to read
 * another person's induction is through the HR endpoints, which are gated on
 * different permissions and will have their own service. Keeping the two apart
 * at this level is what stops "self" quietly becoming "self, or anyone if a
 * caller passes the right argument".
 *
 * Order of operations matters here and is not arbitrary:
 *
 *  1. The record first. No record means an empty summary, and there is nothing
 *     to apply a leaver window to.
 *  2. The leaver window second, BEFORE the assignments are read. A person whose
 *     window has closed is refused rather than being handed a list — and the
 *     refusal costs one query instead of two.
 *  3. The assignments last, sorted and totalled.
 */
final readonly class BuildOnboardingSelfSummaryService
{
    public function __construct(
        private OnboardingSelfServiceRepository $repository,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OnboardingAccessWindowClosed when a departed person's read window has run out
     */
    public function forUser(int $userId): OnboardingSelfSummary
    {
        $record = $this->repository->findTraineeRecord($userId);

        if ($record === null) {
            return OnboardingSelfSummary::none();
        }

        $now = $this->clock->now();
        $departedAtUtc = $record->departedAtUtc;

        if (!LeaverPolicy::mayReadOwnRecords($departedAtUtc, $now)) {
            // hasWindowClosed() is only true when a departure is recorded and in
            // the past, so the window end is computable here.
            self::assertDeparted($departedAtUtc);

            throw OnboardingAccessWindowClosed::endedAt(LeaverPolicy::accessEndsAt($departedAtUtc));
        }

        $assignments = WorklistOrder::sort($this->repository->listAssignments($userId));

        return OnboardingSelfSummary::of(
            $assignments,
            AssignmentProgress::percent(array_map(
                static fn (OnboardingAssignmentView $view) => $view->status,
                $assignments,
            )),
            LeaverPolicy::maySubmit($departedAtUtc, $now),
            $departedAtUtc === null ? null : LeaverPolicy::accessEndsAt($departedAtUtc)->format(DATE_ATOM),
            true,
        );
    }

    /**
     * @phpstan-assert !null $departedAtUtc
     */
    private static function assertDeparted(mixed $departedAtUtc): void
    {
        if ($departedAtUtc === null) {
            throw new \LogicException('LeaverPolicy refused read access without a recorded departure.');
        }
    }
}
