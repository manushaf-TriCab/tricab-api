<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Domain\Entity\GuestPasskeySetupToken;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class InMemoryGuestPasskeySetupTokenRepository implements GuestPasskeySetupTokenRepository
{
    /** @var array<int, GuestPasskeySetupToken> */
    private array $tokens = [];
    private int $nextId = 1;

    public function save(GuestPasskeySetupToken $token): void
    {
        if ($token->id() === null) {
            $token->assignId($this->nextId);
            ++$this->nextId;
        }
        $this->tokens[$token->id() ?? 0] = $token;
    }

    public function findByTokenHash(string $tokenHash): ?GuestPasskeySetupToken
    {
        foreach ($this->tokens as $token) {
            if (hash_equals($token->tokenHash(), $tokenHash)) {
                return $token;
            }
        }

        return null;
    }

    public function findByPublicId(UuidPublicId $publicId): ?GuestPasskeySetupToken
    {
        foreach ($this->tokens as $token) {
            if ($token->publicId()->equals($publicId)) {
                return $token;
            }
        }

        return null;
    }
}
