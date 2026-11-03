<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

/**
 * The step after each employee sync (HR Employees plan 5.2): look at onboarding
 * people with no employee code and store a proposal for each plausible match.
 * It links nobody. Pairs that already have a proposal, decided or not, are not
 * proposed again.
 */
final class OnboardingLinkProposer
{
    public function __construct(private readonly OnboardingLinkRepository $links)
    {
    }

    /**
     * @return array{starters: int, proposed: int}|array{skipped: string}
     */
    public function propose(): array
    {
        if (!$this->links->available()) {
            return ['skipped' => 'The onboarding link schema is not on this database (alter-onboarding-employee-link.sql).'];
        }

        $starters = $this->links->unlinkedStarters();
        if ($starters === []) {
            return ['starters' => 0, 'proposed' => 0];
        }

        $already = $this->links->proposedPairs();
        $fresh = array_values(array_filter(
            OnboardingLinkMatcher::match($starters, $this->links->linkableEmployees()),
            static fn (array $proposal): bool => !isset($already[$proposal['profileId'] . '|' . $proposal['employeeCode']]),
        ));

        return ['starters' => count($starters), 'proposed' => $fresh === [] ? 0 : $this->links->addProposals($fresh)];
    }
}
