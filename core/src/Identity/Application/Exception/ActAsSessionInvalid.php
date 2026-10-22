<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

/**
 * The act-as session named by a request cannot be used right now. The reason
 * is for logs; callers show one generic message so a probe learns nothing.
 */
final class ActAsSessionInvalid extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('Act-as session is not usable: %s.', $reason));
    }
}
