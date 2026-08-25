<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\PasskeyChallenge;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

interface PasskeyChallengeRepository
{
    public function save(PasskeyChallenge $challenge): void;

    public function findByPublicId(UuidPublicId $publicId): ?PasskeyChallenge;
}
