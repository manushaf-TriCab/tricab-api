<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\IssuedGuestAccessToken;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Issues a signed guest access token. Implementations live in the API layer.
 */
interface GuestAccessTokenIssuer
{
    public function issue(
        User $user,
        UuidPublicId $sessionPublicId,
        UuidPublicId $jwtId,
        DateTimeImmutable $issuedAtUtc,
        DateTimeImmutable $expiresAtUtc,
    ): IssuedGuestAccessToken;
}
