<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderSourceUnavailable;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;

final class GetQuoteService
{
    public function __construct(
        private readonly QuoteOrderAccessService $access,
        private readonly QuoteOrderRepository $repository,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    public function execute(QuoteOrderActor $actor, string $quoteId): QuoteDetails
    {
        $scope = $this->access->resolveVisibility($actor);
        if (!$this->legacyReadEnabled) {
            throw QuoteOrderSourceUnavailable::disabled();
        }

        $id = trim($quoteId);
        if ($id === '' || !ctype_digit($id)) {
            throw QuoteNotFound::forId($quoteId);
        }

        if ($scope->forcesEmptyResults()) {
            throw QuoteNotFound::forId($id);
        }

        $details = $this->repository->findQuoteById($id, $scope);
        if ($details === null) {
            throw QuoteNotFound::forId($id);
        }

        // Enumeration-safe: unauthorized codes look like not found.
        if (!$this->access->canViewCustomerCode($scope, $details->customerCode)) {
            throw QuoteNotFound::forId($id);
        }

        return $details;
    }
}
