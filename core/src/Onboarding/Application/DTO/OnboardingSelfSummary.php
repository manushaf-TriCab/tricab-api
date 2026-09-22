<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Everything `GET /api/v1/onboarding/me` answers, and nothing else.
 *
 * Whose summary this is never appears in it. The endpoint takes no identifier,
 * the service is called with the authenticated `UserId`, and the response
 * carries no user id, name or address — so there is no field for a client to
 * change and retry with. Work order §4.3 asks that self-service be self-only;
 * the cheapest way to hold that is for the answer to have no notion of a
 * subject at all.
 */
final readonly class OnboardingSelfSummary
{
    /**
     * @param list<OnboardingAssignmentView> $assignments
     */
    private function __construct(
        public array $assignments,
        public int $progressPercent,
        public int $actionableCount,
        public bool $maySubmit,
        public ?string $accessEndsAtUtc,
        public bool $hasRecord,
    ) {
    }

    /**
     * @param list<OnboardingAssignmentView> $assignments
     */
    public static function of(
        array $assignments,
        int $progressPercent,
        bool $maySubmit,
        ?string $accessEndsAtUtc,
        bool $hasRecord,
    ): self {
        $actionable = 0;
        foreach ($assignments as $assignment) {
            if ($assignment->isActionable()) {
                ++$actionable;
            }
        }

        return new self($assignments, $progressPercent, $actionable, $maySubmit, $accessEndsAtUtc, $hasRecord);
    }

    /**
     * Somebody with no induction record at all — most of the platform. Reported
     * as an empty summary rather than a 404, because "you have no onboarding"
     * is a true and useful answer to "what is my onboarding", and a 404 here
     * would have the SPA render an error page to every ordinary employee who
     * clicked the link.
     */
    public static function none(): self
    {
        return new self([], 0, 0, true, null, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignments' => array_map(
                static fn (OnboardingAssignmentView $view): array => $view->toArray(),
                $this->assignments,
            ),
            'progressPercent' => $this->progressPercent,
            'actionableCount' => $this->actionableCount,
            'maySubmit' => $this->maySubmit,
            'accessEndsAtUtc' => $this->accessEndsAtUtc,
            'hasRecord' => $this->hasRecord,
            'statuses' => AssignmentStatus::ALL,
        ];
    }
}
