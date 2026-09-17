<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchResult;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderVisibilityScope;

interface QuoteOrderRepository
{
    public function search(
        QuoteOrderSearchCriteria $criteria,
        QuoteOrderVisibilityScope $scope,
    ): QuoteOrderSearchResult;

    public function findQuoteById(string $quoteId, QuoteOrderVisibilityScope $scope): ?QuoteDetails;

    public function findOrderById(string $orderId, QuoteOrderVisibilityScope $scope): ?OrderDetails;
}
