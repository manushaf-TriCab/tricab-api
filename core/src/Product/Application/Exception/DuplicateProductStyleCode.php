<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class DuplicateProductStyleCode extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Style code "%s" is already in use.', $code));
    }
}
