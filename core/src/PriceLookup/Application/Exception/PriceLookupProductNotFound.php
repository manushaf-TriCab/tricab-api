<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Exception;

use RuntimeException;

final class PriceLookupProductNotFound extends RuntimeException
{
    public function __construct(string $productCode = '')
    {
        parent::__construct(
            $productCode !== ''
                ? 'Product "' . $productCode . '" was not found.'
                : 'No matching products were found.',
        );
    }
}
