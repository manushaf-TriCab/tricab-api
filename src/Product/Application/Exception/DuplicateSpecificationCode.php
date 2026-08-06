<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class DuplicateSpecificationCode extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Specification code "%s" already exists.', $code));
    }
}
