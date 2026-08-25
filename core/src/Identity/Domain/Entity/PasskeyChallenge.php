<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class PasskeyChallenge
{
    public function __construct(
        private ?int $id,
        private readonly UuidPublicId $publicId,
        private readonly ?UserId $userId,
        private readonly ?UuidPublicId $registrationSessionPublicId,
        private readonly PasskeyChallengePurpose $purpose,
        private readonly string $challengeBinary,
        private readonly DateTimeImmutable $expiresAtUtc,
        private ?DateTimeImmutable $consumedAtUtc,
        private readonly DateTimeImmutable $createdAtUtc,
    ) {
    }

    public function id(): ?int { return $this->id; }
    public function assignId(int $id): void { $this->id = $id; }
    public function publicId(): UuidPublicId { return $this->publicId; }
    public function userId(): ?UserId { return $this->userId; }
    public function registrationSessionPublicId(): ?UuidPublicId { return $this->registrationSessionPublicId; }
    public function purpose(): PasskeyChallengePurpose { return $this->purpose; }
    public function challengeBinary(): string { return $this->challengeBinary; }
    public function expiresAtUtc(): DateTimeImmutable { return $this->expiresAtUtc; }
    public function consumedAtUtc(): ?DateTimeImmutable { return $this->consumedAtUtc; }
    public function createdAtUtc(): DateTimeImmutable { return $this->createdAtUtc; }

    public function assertUsable(DateTimeImmutable $now, PasskeyChallengePurpose $expected): void
    {
        if ($this->purpose->toString() !== $expected->toString()) {
            throw PasskeyAuthFailed::challengeInvalid();
        }
        if ($this->consumedAtUtc !== null || $now >= $this->expiresAtUtc) {
            throw PasskeyAuthFailed::challengeInvalid();
        }
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->consumedAtUtc = $now;
    }
}
