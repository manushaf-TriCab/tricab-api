<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class UserPasskey
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        private ?int $id,
        private readonly UuidPublicId $publicId,
        private readonly UserId $userId,
        private readonly string $credentialId,
        private readonly string $publicKey,
        private int $signCount,
        private array $transports,
        private readonly ?string $aaguid,
        private string $deviceLabel,
        private readonly DateTimeImmutable $createdAtUtc,
        private ?DateTimeImmutable $lastUsedAtUtc,
        private bool $isActive,
    ) {
        $label = trim($deviceLabel);
        $this->deviceLabel = $label === '' ? 'Passkey' : substr($label, 0, 120);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function publicId(): UuidPublicId
    {
        return $this->publicId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function signCount(): int
    {
        return $this->signCount;
    }

    /** @return list<string> */
    public function transports(): array
    {
        return $this->transports;
    }

    public function aaguid(): ?string
    {
        return $this->aaguid;
    }

    public function deviceLabel(): string
    {
        return $this->deviceLabel;
    }

    public function createdAtUtc(): DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function lastUsedAtUtc(): ?DateTimeImmutable
    {
        return $this->lastUsedAtUtc;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function rename(string $label): void
    {
        $normalized = trim($label);
        $this->deviceLabel = $normalized === '' ? 'Passkey' : substr($normalized, 0, 120);
    }

    public function revoke(): void
    {
        $this->isActive = false;
    }

    public function recordUse(DateTimeImmutable $usedAtUtc, int $signCount): void
    {
        $this->lastUsedAtUtc = $usedAtUtc;
        $this->signCount = $signCount;
    }
}
