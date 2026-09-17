<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;
use TriCab\Core\QuoteOrder\Application\Exception\OrderNotFound;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteOrderSourceUnavailable;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;

final class GetOrderService
{
    public function __construct(
        private readonly QuoteOrderAccessService $access,
        private readonly QuoteOrderRepository $repository,
        private readonly bool $legacyReadEnabled = false,
    ) {
    }

    public function execute(QuoteOrderActor $actor, string $orderId): OrderDetails
    {
        $scope = $this->access->resolveVisibility($actor);
        if (!$this->legacyReadEnabled) {
            throw QuoteOrderSourceUnavailable::disabled();
        }

        $id = trim($orderId);
        if ($id === '') {
            throw OrderNotFound::forId($orderId);
        }

        if ($scope->forcesEmptyResults()) {
            throw OrderNotFound::forId($id);
        }

        $details = $this->repository->findOrderById($id, $scope);
        if ($details === null) {
            throw OrderNotFound::forId($id);
        }

        $customerCode = $details->quote?->customerCode;
        $this->access->assertCanViewCustomerCode($scope, $customerCode);

        return $details;
    }
}
