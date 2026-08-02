<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\Exception\GuestSessionExpired;
use TriCab\Core\Identity\Domain\Exception\GuestSessionRevoked;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\GuestSessionId;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Guest browser/session record. JWT issuance remains an API concern.
 */
final class GuestSession
{
    private function __construct(
        private ?GuestSessionId $id,
        private readonly UuidPublicId $publicId,
        private readonly UserId $userId,
        private readonly SessionTokenHash $sessionTokenHash,
        private readonly ?UuidPublicId $jwtId,
        private readonly DateTimeImmutable $issuedAtUtc,
        private readonly DateTimeImmutable $expiresAtUtc,
        private ?DateTimeImmutable $revokedAtUtc,
        private ?DateTimeImmutable $lastUsedAtUtc,
        private readonly ?string $createdIpAddress,
        private readonly ?string $createdUserAgent,
        private readonly DateTimeImmutable $createdAtUtc,
    ) {
    }

    public static function open(
        UuidPublicId $publicId,
        UserId $userId,
        SessionTokenHash $sessionTokenHash,
        DateTimeImmutable $issuedAtUtc,
        DateTimeImmutable $expiresAtUtc,
        ?UuidPublicId $jwtId = null,
        ?string $createdIpAddress = null,
        ?string $createdUserAgent = null,
    ): self {
        if ($expiresAtUtc <= $issuedAtUtc) {
            throw InvalidIdentityValue::for('ExpiresAtUtc', $expiresAtUtc->format(DATE_ATOM));
        }

        return new self(
            id: null,
            publicId: $publicId,
            userId: $userId,
            sessionTokenHash: $sessionTokenHash,
            jwtId: $jwtId,
            issuedAtUtc: $issuedAtUtc,
            expiresAtUtc: $expiresAtUtc,
            revokedAtUtc: null,
            lastUsedAtUtc: null,
            createdIpAddress: self::nullableTrim($createdIpAddress),
            createdUserAgent: self::nullableTrim($createdUserAgent),
            createdAtUtc: $issuedAtUtc,
        );
    }

    public static function reconstitute(
        GuestSessionId $id,
        UuidPublicId $publicId,
        UserId $userId,
        SessionTokenHash $sessionTokenHash,
        ?UuidPublicId $jwtId,
        DateTimeImmutable $issuedAtUtc,
        DateTimeImmutable $expiresAtUtc,
        ?DateTimeImmutable $revokedAtUtc,
        ?DateTimeImmutable $lastUsedAtUtc,
        ?string $createdIpAddress,
        ?string $createdUserAgent,
        DateTimeImmutable $createdAtUtc,
    ): self {
        return new self(
            id: $id,
            publicId: $publicId,
            userId: $userId,
            sessionTokenHash: $sessionTokenHash,
            jwtId: $jwtId,
            issuedAtUtc: $issuedAtUtc,
            expiresAtUtc: $expiresAtUtc,
            revokedAtUtc: $revokedAtUtc,
            lastUsedAtUtc: $lastUsedAtUtc,
            createdIpAddress: self::nullableTrim($createdIpAddress),
            createdUserAgent: self::nullableTrim($createdUserAgent),
            createdAtUtc: $createdAtUtc,
        );
    }

    public function assignId(GuestSessionId $id): void
    {
        if ($this->id !== null) {
            throw InvalidIdentityValue::for('GuestSessionId', 'already assigned');
        }

        $this->id = $id;
    }

    public function id(): ?GuestSessionId
    {
        return $this->id;
    }

    public function publicId(): UuidPublicId
    {
        return $this->publicId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function sessionTokenHash(): SessionTokenHash
    {
        return $this->sessionTokenHash;
    }

    public function jwtId(): ?UuidPublicId
    {
        return $this->jwtId;
    }

    public function issuedAtUtc(): DateTimeImmutable
    {
        return $this->issuedAtUtc;
    }

    public function expiresAtUtc(): DateTimeImmutable
    {
        return $this->expiresAtUtc;
    }

    public function revokedAtUtc(): ?DateTimeImmutable
    {
        return $this->revokedAtUtc;
    }

    public function lastUsedAtUtc(): ?DateTimeImmutable
    {
        return $this->lastUsedAtUtc;
    }

    public function createdIpAddress(): ?string
    {
        return $this->createdIpAddress;
    }

    public function createdUserAgent(): ?string
    {
        return $this->createdUserAgent;
    }

    public function createdAtUtc(): DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAtUtc;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAtUtc !== null;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return !$this->isExpired($now) && !$this->isRevoked();
    }

    public function assertActive(DateTimeImmutable $now): void
    {
        if ($this->isRevoked()) {
            throw GuestSessionRevoked::create();
        }

        if ($this->isExpired($now)) {
            throw GuestSessionExpired::create();
        }
    }

    public function touch(DateTimeImmutable $now): void
    {
        $this->assertActive($now);
        $this->lastUsedAtUtc = $now;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        if ($this->isRevoked()) {
            return;
        }

        if ($now < $this->issuedAtUtc) {
            throw InvalidIdentityValue::for('RevokedAtUtc', $now->format(DATE_ATOM));
        }

        $this->revokedAtUtc = $now;
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
