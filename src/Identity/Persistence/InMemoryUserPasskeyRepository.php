<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Domain\Entity\UserPasskey;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Identity\Domain\ValueObject\WebAuthnUserHandle;

final class InMemoryUserPasskeyRepository implements UserPasskeyRepository
{
    /** @var array<int, UserPasskey> */
    private array $passkeys = [];
    /** @var array<int, string> */
    private array $handles = [];
    private int $nextId = 1;

    public function save(UserPasskey $passkey): void
    {
        if ($passkey->id() === null) {
            $passkey->assignId($this->nextId);
            ++$this->nextId;
        }
        $this->passkeys[$passkey->id() ?? 0] = $passkey;
    }

    public function findByCredentialId(string $credentialId): ?UserPasskey
    {
        foreach ($this->passkeys as $passkey) {
            if (hash_equals($passkey->credentialId(), $credentialId)) {
                return $passkey;
            }
        }

        return null;
    }

    public function findByPublicId(UuidPublicId $publicId): ?UserPasskey
    {
        foreach ($this->passkeys as $passkey) {
            if ($passkey->publicId()->equals($publicId)) {
                return $passkey;
            }
        }

        return null;
    }

    public function listActiveByUserId(UserId $userId): array
    {
        $out = [];
        foreach ($this->passkeys as $passkey) {
            if ($passkey->userId()->equals($userId) && $passkey->isActive()) {
                $out[] = $passkey;
            }
        }

        return $out;
    }

    public function findHandleByUserId(UserId $userId): ?WebAuthnUserHandle
    {
        $hex = $this->handles[$userId->toInt()] ?? null;

        return $hex === null ? null : WebAuthnUserHandle::fromHex($hex);
    }

    public function saveHandle(UserId $userId, WebAuthnUserHandle $handle): void
    {
        $this->handles[$userId->toInt()] = $handle->toHex();
    }

    public function findUserIdByHandle(WebAuthnUserHandle $handle): ?UserId
    {
        foreach ($this->handles as $userId => $hex) {
            if (hash_equals($hex, $handle->toHex())) {
                return UserId::fromInt($userId);
            }
        }

        return null;
    }
}
