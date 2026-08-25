<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\GuestPasskeySetupToken;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

interface GuestPasskeySetupTokenRepository
{
    public function save(GuestPasskeySetupToken $token): void;

    public function findByTokenHash(string $tokenHash): ?GuestPasskeySetupToken;

    public function findByPublicId(UuidPublicId $publicId): ?GuestPasskeySetupToken;
}

