<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class DuplicateProductSlug extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Product slug "%s" is already in use.', $slug));
    }
}
