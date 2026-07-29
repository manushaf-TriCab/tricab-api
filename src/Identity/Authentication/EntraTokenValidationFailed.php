<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

final class EntraTokenValidationFailed extends RuntimeException
{
    public static function create(string $reason): self
    {
        return new self(sprintf('Microsoft Entra token validation failed: %s', $reason));
    }
}
