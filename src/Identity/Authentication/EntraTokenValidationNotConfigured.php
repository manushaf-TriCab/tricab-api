<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

final class EntraTokenValidationNotConfigured extends RuntimeException
{
    public static function create(): self
    {
        return new self('Microsoft Entra JWT validation is not configured.');
    }
}
