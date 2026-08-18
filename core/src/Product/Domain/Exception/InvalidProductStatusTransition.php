<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Exception;

use DomainException;

final class InvalidProductStatusTransition extends DomainException
{
    public static function from(string $from, string $to): self
    {
        return new self(sprintf('Product status transition from "%s" to "%s" is not allowed.', $from, $to));
    }
}
