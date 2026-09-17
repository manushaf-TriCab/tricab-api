<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchResult;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderSourceUnavailable;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;

final class SearchQuoteOrdersService
{
    public function __construct(
        private readonly QuoteOrderAccessService $access,
        private readonly QuoteOrderRepository $repository,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    public function execute(QuoteOrderActor $actor, QuoteOrderSearchCriteria $criteria): QuoteOrderSearchResult
    {
        $scope = $this->access->resolveVisibility($actor);
        $this->assertSourceEnabled();

        if ($scope->forcesEmptyResults()) {
            return new QuoteOrderSearchResult(
                items: [],
                page: $criteria->page,
                pageSize: $criteria->pageSize,
                total: 0,
            );
        }

        return $this->repository->search($criteria, $scope);
    }

    private function assertSourceEnabled(): void
    {
        if (!$this->legacyReadEnabled) {
            throw QuoteOrderSourceUnavailable::disabled();
        }
    }
}
