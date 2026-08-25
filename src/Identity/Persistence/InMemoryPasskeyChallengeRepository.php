<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyChallenge;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class InMemoryPasskeyChallengeRepository implements PasskeyChallengeRepository
{
    /** @var array<int, PasskeyChallenge> */
    private array $challenges = [];
    private int $nextId = 1;

    public function save(PasskeyChallenge $challenge): void
    {
        if ($challenge->id() === null) {
            $challenge->assignId($this->nextId);
            ++$this->nextId;
        }
        $this->challenges[$challenge->id() ?? 0] = $challenge;
    }

    public function findByPublicId(UuidPublicId $publicId): ?PasskeyChallenge
    {
        foreach ($this->challenges as $challenge) {
            if ($challenge->publicId()->equals($publicId)) {
                return $challenge;
            }
        }

        return null;
    }
}
