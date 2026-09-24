<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Exception;

use RuntimeException;

final class PriceLookupUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode = 'PRICE_UNAVAILABLE',
        string $message = 'Unable to calculate price right now.',
    ) {
        parent::__construct($message);
    }
}
