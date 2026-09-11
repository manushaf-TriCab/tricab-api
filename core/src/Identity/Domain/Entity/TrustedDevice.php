<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceExpired;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceRevoked;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\TrustedDeviceId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Long-lived hashed device credential for biometric return login.
 * Raw credentials are never stored — only SHA-256 hashes.
 */
final class TrustedDevice
{
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';

    public const CREDENTIAL_ROTATION_GRACE = 'PT24H';

    private function __construct(
        private ?TrustedDeviceId $id,
        private readonly UuidPublicId $publicId,
        private readonly UserId $userId,
        private SessionTokenHash $credentialHash,
        private ?SessionTokenHash $previousCredentialHash,
        private ?DateTimeImmutable $previousCredentialValidUntilUtc,
        private readonly UuidPublicId $installationId,
        private readonly string $platform,
        private readonly ?string $deviceName,
        private int $credentialVersion,
        private readonly DateTimeImmutable $createdAtUtc,
        private ?DateTimeImmutable $lastUsedAtUtc,
        private readonly DateTimeImmutable $expiresAtUtc,
        private ?DateTimeImmutable $revokedAtUtc,
        private readonly ?string $createdIp,
        private ?string $lastUsedIp,
    ) {
    }

    public static function register(
        UuidPublicId $publicId,
        UserId $userId,
        SessionTokenHash $credentialHash,
        UuidPublicId $installationId,
        string $platform,
        DateTimeImmutable $createdAtUtc,
        DateTimeImmutable $expiresAtUtc,
        ?string $deviceName = null,
        ?string $createdIp = null,
    ): self {
        $normalizedPlatform = self::normalizePlatform($platform);

        if ($expiresAtUtc <= $createdAtUtc) {
            throw InvalidIdentityValue::for('ExpiresAtUtc', $expiresAtUtc->format(DATE_ATOM));
        }

        return new self(
            id: null,
            publicId: $publicId,
            userId: $userId,
            credentialHash: $credentialHash,
            previousCredentialHash: null,
            previousCredentialValidUntilUtc: null,
            installationId: $installationId,
            platform: $normalizedPlatform,
            deviceName: self::nullableTrim($deviceName, 120),
            credentialVersion: 1,
            createdAtUtc: $createdAtUtc,
            lastUsedAtUtc: null,
            expiresAtUtc: $expiresAtUtc,
            revokedAtUtc: null,
            createdIp: self::nullableTrim($createdIp, 45),
            lastUsedIp: null,
        );
    }

    public static function reconstitute(
        TrustedDeviceId $id,
        UuidPublicId $publicId,
        UserId $userId,
        SessionTokenHash $credentialHash,
        ?SessionTokenHash $previousCredentialHash,
        ?DateTimeImmutable $previousCredentialValidUntilUtc,
        UuidPublicId $installationId,
        string $platform,
        ?string $deviceName,
        int $credentialVersion,
        DateTimeImmutable $createdAtUtc,
        ?DateTimeImmutable $lastUsedAtUtc,
        DateTimeImmutable $expiresAtUtc,
        ?DateTimeImmutable $revokedAtUtc,
        ?string $createdIp,
        ?string $lastUsedIp,
    ): self {
        if ($credentialVersion < 1) {
            throw InvalidIdentityValue::for('CredentialVersion', (string) $credentialVersion);
        }

        return new self(
            id: $id,
            publicId: $publicId,
            userId: $userId,
            credentialHash: $credentialHash,
            previousCredentialHash: $previousCredentialHash,
            previousCredentialValidUntilUtc: $previousCredentialValidUntilUtc,
            installationId: $installationId,
            platform: self::normalizePlatform($platform),
            deviceName: self::nullableTrim($deviceName, 120),
            credentialVersion: $credentialVersion,
            createdAtUtc: $createdAtUtc,
            lastUsedAtUtc: $lastUsedAtUtc,
            expiresAtUtc: $expiresAtUtc,
            revokedAtUtc: $revokedAtUtc,
            createdIp: self::nullableTrim($createdIp, 45),
            lastUsedIp: self::nullableTrim($lastUsedIp, 45),
        );
    }

    public function assignId(TrustedDeviceId $id): void
    {
        if ($this->id !== null) {
            throw InvalidIdentityValue::for('TrustedDeviceId', 'already assigned');
        }

        $this->id = $id;
    }

    public function id(): ?TrustedDeviceId
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

    public function credentialHash(): SessionTokenHash
    {
        return $this->credentialHash;
    }

    public function previousCredentialHash(): ?SessionTokenHash
    {
        return $this->previousCredentialHash;
    }

    public function previousCredentialValidUntilUtc(): ?DateTimeImmutable
    {
        return $this->previousCredentialValidUntilUtc;
    }

    public function installationId(): UuidPublicId
    {
        return $this->installationId;
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function deviceName(): ?string
    {
        return $this->deviceName;
    }

    public function credentialVersion(): int
    {
        return $this->credentialVersion;
    }

    public function createdAtUtc(): DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function lastUsedAtUtc(): ?DateTimeImmutable
    {
        return $this->lastUsedAtUtc;
    }

    public function expiresAtUtc(): DateTimeImmutable
    {
        return $this->expiresAtUtc;
    }

    public function revokedAtUtc(): ?DateTimeImmutable
    {
        return $this->revokedAtUtc;
    }

    public function createdIp(): ?string
    {
        return $this->createdIp;
    }

    public function lastUsedIp(): ?string
    {
        return $this->lastUsedIp;
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
            throw TrustedDeviceRevoked::create();
        }

        if ($this->isExpired($now)) {
            throw TrustedDeviceExpired::create();
        }
    }

    /**
     * @return 'current'|'previous'|null
     */
    public function matchesCredential(SessionTokenHash $hash, DateTimeImmutable $now): ?string
    {
        if ($this->credentialHash->equals($hash)) {
            return 'current';
        }

        if (
            $this->previousCredentialHash !== null
            && $this->previousCredentialValidUntilUtc !== null
            && $this->previousCredentialHash->equals($hash)
            && $now < $this->previousCredentialValidUntilUtc
        ) {
            return 'previous';
        }

        return null;
    }

    /**
     * Rotate current credential; previous hash remains valid until grace ends
     * (min of now+24h and device expiry).
     */
    public function rotateCredential(
        SessionTokenHash $newCredentialHash,
        DateTimeImmutable $now,
        bool $preserveMatchedPrevious = false,
    ): void {
        $this->assertActive($now);

        if ($preserveMatchedPrevious && $this->previousCredentialHash !== null) {
            // Previous-path recovery: keep the matched previous hash in grace and
            // replace the current hash with a fresh credential the client can store.
            $this->previousCredentialValidUntilUtc = $this->graceUntil($now);
        } else {
            $this->previousCredentialHash = $this->credentialHash;
            $this->previousCredentialValidUntilUtc = $this->graceUntil($now);
        }

        $this->credentialHash = $newCredentialHash;
        ++$this->credentialVersion;
        $this->lastUsedAtUtc = $now;
    }

    public function touchLastUsed(DateTimeImmutable $now, ?string $lastUsedIp = null): void
    {
        $this->assertActive($now);
        $this->lastUsedAtUtc = $now;
        if ($lastUsedIp !== null) {
            $this->lastUsedIp = self::nullableTrim($lastUsedIp, 45);
        }
    }

    public function revoke(DateTimeImmutable $now): void
    {
        if ($this->isRevoked()) {
            return;
        }

        if ($now < $this->createdAtUtc) {
            throw InvalidIdentityValue::for('RevokedAtUtc', $now->format(DATE_ATOM));
        }

        $this->revokedAtUtc = $now;
        $this->previousCredentialHash = null;
        $this->previousCredentialValidUntilUtc = null;
    }

    private function graceUntil(DateTimeImmutable $now): DateTimeImmutable
    {
        $grace = $now->add(new DateInterval(self::CREDENTIAL_ROTATION_GRACE));

        return $grace < $this->expiresAtUtc ? $grace : $this->expiresAtUtc;
    }

    private static function normalizePlatform(string $platform): string
    {
        $normalized = strtolower(trim($platform));
        if ($normalized !== self::PLATFORM_IOS && $normalized !== self::PLATFORM_ANDROID) {
            throw InvalidIdentityValue::for('Platform', $platform);
        }

        return $normalized;
    }

    private static function nullableTrim(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $maxLength) {
            $trimmed = mb_substr($trimmed, 0, $maxLength);
        }

        return $trimmed;
    }
}
