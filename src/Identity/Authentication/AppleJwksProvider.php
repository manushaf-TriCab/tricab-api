<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

/**
 * Fetches Apple OpenID JWKS for ID-token signature validation.
 */
interface AppleJwksProvider
{
    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function fetch(): array;
}
