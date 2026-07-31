<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

final class EntraTokenValidationFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'UNAUTHENTICATED',
        public readonly int $httpStatus = 401,
    ) {
        parent::__construct($message);
    }

    public static function create(string $reason): self
    {
        return new self(
            sprintf('Microsoft Entra token validation failed: %s', $reason),
            'UNAUTHENTICATED',
            401,
        );
    }

    public static function insufficientScope(string $reason): self
    {
        return new self(
            sprintf('Microsoft Entra token validation failed: %s', $reason),
            'INSUFFICIENT_SCOPE',
            403,
        );
    }
}
