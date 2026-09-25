<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;

/**
 * The HR roster, one bounded page at a time (work order §8).
 */
final readonly class ListOnboardingPeopleService
{
    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private OnboardingPeopleRepository $people,
    ) {
    }

    /**
     * @return array{items: list<OnboardingPersonSummary>, total: int, page: int, pageSize: int}
     */
    public function list(int $page, int $pageSize, ?string $search): array
    {
        $page = max(1, $page);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));
        $term = $search === null ? '' : trim($search);

        $result = $this->people->listPeople($page, $pageSize, $term === '' ? null : mb_substr($term, 0, 100));

        return [...$result, 'page' => $page, 'pageSize' => $pageSize];
    }
}
