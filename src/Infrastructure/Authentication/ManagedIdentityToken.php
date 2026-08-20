<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

/**
 * Short-lived Entra access token from App Service / IMDS managed identity.
 * Callers must not log {@see $token}.
 */
final readonly class ManagedIdentityToken
{
    public function __construct(
        public string $token,
        public int $expiresAt,
        public string $endpointKind,
    ) {
    }
}
