<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\ProductLookupRepository;

/**
 * @phpstan-import-type LookupBundle from ProductLookupRepository
 */
final class GetProductLookupsService
{
    public function __construct(
        private readonly ProductLookupRepository $lookups,
    ) {
    }

    /**
     * @return LookupBundle
     */
    public function execute(): array
    {
        return $this->lookups->loadAdminLookups();
    }
}
