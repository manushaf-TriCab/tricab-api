<?php

declare(strict_types=1);

namespace TriCab\Core\ProductFinder\Application\Client;

use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;
use TriCab\Core\ProductFinder\Application\DTO\ProductFinderResult;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;

/**
 * The finder, switched off.
 *
 * Wired in when `PRODUCT_FINDER_ENABLED` is false, and used by tests and by a
 * local environment with no Python running. Follows
 * `NullCableCalculatorProductResolver`: a real object that answers honestly,
 * so `POST /api/v1/product-finder` behaves the same whether the service is
 * absent, disabled or merely down — one degraded path to reason about instead
 * of three.
 */
final readonly class NullProductFinderClient implements ProductFinderClient
{
    public function __construct(
        private string $detail = 'The product finder is not enabled in this environment.',
    ) {
    }

    public function find(ProductFinderQuery $query): ProductFinderResult
    {
        unset($query);

        return ProductFinderResult::unavailable($this->detail);
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
