<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class PasskeyRegistrationSession
{
    public function __construct(
        private ?int $id,
        private readonly UuidPublicId $publicId,
        private readonly UserId $userId,
        private readonly UuidPublicId $setupTokenPublicId,
        private readonly string $sessionTokenHash,
        private readonly DateTimeImmutable $expiresAtUtc,
        private ?DateTimeImmutable $consumedAtUtc,
        private readonly DateTimeImmutable $createdAtUtc,
    ) {
    }

    public function id(): ?int { return $this->id; }
    public function assignId(int $id): void { $this->id = $id; }
    public function publicId(): UuidPublicId { return $this->publicId; }
    public function userId(): UserId { return $this->userId; }
    public function setupTokenPublicId(): UuidPublicId { return $this->setupTokenPublicId; }
    public function sessionTokenHash(): string { return $this->sessionTokenHash; }
    public function expiresAtUtc(): DateTimeImmutable { return $this->expiresAtUtc; }
    public function consumedAtUtc(): ?DateTimeImmutable { return $this->consumedAtUtc; }
    public function createdAtUtc(): DateTimeImmutable { return $this->createdAtUtc; }

    public function assertUsable(DateTimeImmutable $now): void
    {
        if ($this->consumedAtUtc !== null || $now >= $this->expiresAtUtc) {
            throw PasskeyAuthFailed::registrationSessionInvalid();
        }
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->assertUsable($now);
        $this->consumedAtUtc = $now;
    }
}
