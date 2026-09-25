<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Exception;

use RuntimeException;

final class PriceLookupForbidden extends RuntimeException
{
    public function __construct(string $message = 'You do not have permission to perform this action.')
    {
        parent::__construct($message);
    }
}
