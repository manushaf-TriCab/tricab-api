<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class GuestPasskeySetupToken
{
    public function __construct(
        private ?int $id,
        private readonly UuidPublicId $publicId,
        private readonly UserId $userId,
        private readonly string $tokenHash,
        private readonly DateTimeImmutable $expiresAtUtc,
        private ?DateTimeImmutable $consumedAtUtc,
        private readonly DateTimeImmutable $createdAtUtc,
        private readonly ?string $requestedIpHash,
    ) {
    }

    public function id(): ?int { return $this->id; }
    public function assignId(int $id): void { $this->id = $id; }
    public function publicId(): UuidPublicId { return $this->publicId; }
    public function userId(): UserId { return $this->userId; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function expiresAtUtc(): DateTimeImmutable { return $this->expiresAtUtc; }
    public function consumedAtUtc(): ?DateTimeImmutable { return $this->consumedAtUtc; }
    public function createdAtUtc(): DateTimeImmutable { return $this->createdAtUtc; }
    public function requestedIpHash(): ?string { return $this->requestedIpHash; }

    public function assertUsable(DateTimeImmutable $now): void
    {
        if ($this->consumedAtUtc !== null) {
            throw PasskeyAuthFailed::setupTokenConsumed();
        }
        if ($now >= $this->expiresAtUtc) {
            throw PasskeyAuthFailed::setupTokenExpired();
        }
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->assertUsable($now);
        $this->consumedAtUtc = $now;
    }
}
