<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class OrderNotFound extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Order not found: %s', $id));
    }
}
