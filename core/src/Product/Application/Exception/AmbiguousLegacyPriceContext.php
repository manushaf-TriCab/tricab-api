<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class AmbiguousLegacyPriceContext extends RuntimeException
{
    public static function forContext(string $sourceCode, string $contextType, string $contextCode, int $matchCount): self
    {
        return new self(sprintf(
            'Legacy pricing context %s / %s / %s matched %d active price lists; expected exactly one.',
            $sourceCode,
            $contextType,
            $contextCode,
            $matchCount,
        ));
    }
}
