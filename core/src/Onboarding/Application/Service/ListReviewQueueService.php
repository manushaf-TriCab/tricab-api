<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Contract\OnboardingReviewRepository;

/**
 * The reviewer queue, one bounded page at a time (work order §8).
 */
final readonly class ListReviewQueueService
{
    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private OnboardingReviewRepository $reviews,
    ) {
    }

    /**
     * @return array{items: list<ReviewQueueEntry>, total: int, page: int, pageSize: int}
     */
    public function list(int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));

        return [...$this->reviews->listQueue($page, $pageSize), 'page' => $page, 'pageSize' => $pageSize];
    }
}
