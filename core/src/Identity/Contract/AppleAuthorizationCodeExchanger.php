<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

/**
 * Exchanges an Apple authorization code for an ID token.
 * Implementations live in the API layer. Never log the code or ID token.
 */
interface AppleAuthorizationCodeExchanger
{
    /**
     * @return string Apple ID token (JWT)
     */
    public function exchange(string $authorizationCode): string;
}
