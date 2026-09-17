<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Legacy;

use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchResult;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;

/** Used when quotes/orders legacy read is disabled or TC MySQL is unavailable. */
final class NullQuoteOrderRepository implements QuoteOrderRepository
{
    public function search(
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): QuoteOrderSearchResult {
        return new QuoteOrderSearchResult(
            items: [],
            page: $criteria->page,
            pageSize: $criteria->pageSize,
            total: 0,
        );
    }

    public function findQuoteById(string $quoteId, QuoteOrderVisibilityScope $scope): ?QuoteDetails
    {
        return null;
    }

    public function findOrderById(string $orderId, QuoteOrderVisibilityScope $scope): ?OrderDetails
    {
        return null;
    }
}
