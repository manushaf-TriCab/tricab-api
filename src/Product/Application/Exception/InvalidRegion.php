<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use InvalidArgumentException;

final class InvalidRegion extends InvalidArgumentException
{
    public static function field(string $field, string $message): self
    {
        return new self(sprintf('Invalid region %s: %s', $field, $message));
    }
}
