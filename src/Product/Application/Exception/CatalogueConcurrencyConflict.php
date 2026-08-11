<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class CatalogueConcurrencyConflict extends RuntimeException
{
    public static function forTab(): self
    {
        return new self('This catalogue tab was changed by another user. Reload the latest version before saving.');
    }

    public static function forNode(): self
    {
        return new self('This catalogue category was changed by another user. Reload the latest version before saving.');
    }
}
