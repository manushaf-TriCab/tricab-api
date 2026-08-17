<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

/**
 * Fetches Google OpenID JWKS for ID-token signature validation.
 */
interface GoogleJwksProvider
{
    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function fetch(): array;
}
