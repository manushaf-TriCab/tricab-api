<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class ManageUserPasskeysService
{
    public function __construct(
        private readonly UserPasskeyRepository $passkeys,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(UserId $userId): array
    {
        $out = [];
        foreach ($this->passkeys->listActiveByUserId($userId) as $passkey) {
            $out[] = [
                'publicId' => $passkey->publicId()->toString(),
                'deviceLabel' => $passkey->deviceLabel(),
                'createdAtUtc' => $passkey->createdAtUtc()->format(DATE_ATOM),
                'lastUsedAtUtc' => $passkey->lastUsedAtUtc()?->format(DATE_ATOM),
            ];
        }

        return $out;
    }

    public function rename(UserId $userId, string $publicId, string $label): void
    {
        $passkey = $this->owned($userId, $publicId);
        $passkey->rename($label);
        $this->passkeys->save($passkey);
    }

    public function revoke(UserId $userId, string $publicId): void
    {
        $passkey = $this->owned($userId, $publicId);
        $passkey->revoke();
        $this->passkeys->save($passkey);
    }

    private function owned(UserId $userId, string $publicId): \TriCab\Core\Identity\Domain\Entity\UserPasskey
    {
        $passkey = $this->passkeys->findByPublicId(UuidPublicId::fromString($publicId));
        if ($passkey === null || !$passkey->userId()->equals($userId) || !$passkey->isActive()) {
            throw PasskeyAuthFailed::unknownCredential();
        }

        return $passkey;
    }
}
