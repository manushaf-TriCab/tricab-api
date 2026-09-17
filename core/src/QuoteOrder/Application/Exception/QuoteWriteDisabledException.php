<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class QuoteWriteDisabledException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self(
            'Quote legacy write is disabled. Set QUOTES_ORDERS_LEGACY_WRITE_ENABLED=true with dedicated write credentials to enable.',
        );
    }
}
