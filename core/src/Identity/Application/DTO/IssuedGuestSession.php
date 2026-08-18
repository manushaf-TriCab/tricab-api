<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Safe response after issuing a guest session + access token.
 */
final readonly class IssuedGuestSession
{
    /**
     * @param array{userId: int, publicId: string, emailAddress: string, displayName: string, status: string, roles: list<string>, permissions: list<string>} $user
     */
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
        public string $expiresAtUtc,
        public array $user,
    ) {
    }
}
