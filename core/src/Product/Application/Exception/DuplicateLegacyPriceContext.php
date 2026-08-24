<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class DuplicateLegacyPriceContext extends RuntimeException
{
    public static function forContext(string $sourceCode, string $contextType, string $contextCode): self
    {
        return new self(sprintf(
            'Legacy pricing context %s / %s / %s is already mapped to another price list.',
            $sourceCode,
            $contextType,
            $contextCode,
        ));
    }
}
