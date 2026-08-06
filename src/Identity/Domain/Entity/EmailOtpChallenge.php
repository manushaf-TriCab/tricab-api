<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Exception\OtpAttemptsExceeded;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeConsumed;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeExpired;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeInvalidated;
use TriCab\Core\Identity\Domain\Exception\OtpInvalid;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailOtpChallengeId;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

/**
 * Email OTP challenge aggregate with attempt, expiry, and consumption rules.
 */
final class EmailOtpChallenge
{
    public const DEFAULT_MAXIMUM_ATTEMPTS = 5;

    private function __construct(
        private ?EmailOtpChallengeId $id,
        private readonly UuidPublicId $publicId,
        private readonly EmailAddress $emailAddress,
        private readonly string $otpHash,
        private readonly OtpPurpose $purpose,
        private readonly DateTimeImmutable $expiresAtUtc,
        private int $attemptCount,
        private readonly int $maximumAttempts,
        private ?DateTimeImmutable $consumedAtUtc,
        private ?DateTimeImmutable $invalidatedAtUtc,
        private readonly ?string $requestedIpAddress,
        private readonly ?string $requestedUserAgent,
        private readonly DateTimeImmutable $createdAtUtc,
    ) {
    }

    public static function request(
        UuidPublicId $publicId,
        EmailAddress $emailAddress,
        string $otpHash,
        OtpPurpose $purpose,
        DateTimeImmutable $createdAtUtc,
        DateTimeImmutable $expiresAtUtc,
        int $maximumAttempts = self::DEFAULT_MAXIMUM_ATTEMPTS,
        ?string $requestedIpAddress = null,
        ?string $requestedUserAgent = null,
    ): self {
        $hash = trim($otpHash);
        if ($hash === '') {
            throw InvalidIdentityValue::for('OtpHash', $otpHash);
        }

        if ($maximumAttempts < 1) {
            throw InvalidIdentityValue::for('MaximumAttempts', (string) $maximumAttempts);
        }

        if ($expiresAtUtc <= $createdAtUtc) {
            throw InvalidIdentityValue::for('ExpiresAtUtc', $expiresAtUtc->format(DATE_ATOM));
        }

        return new self(
            id: null,
            publicId: $publicId,
            emailAddress: $emailAddress,
            otpHash: $hash,
            purpose: $purpose,
            expiresAtUtc: $expiresAtUtc,
            attemptCount: 0,
            maximumAttempts: $maximumAttempts,
            consumedAtUtc: null,
            invalidatedAtUtc: null,
            requestedIpAddress: self::nullableTrim($requestedIpAddress),
            requestedUserAgent: self::nullableTrim($requestedUserAgent),
            createdAtUtc: $createdAtUtc,
        );
    }

    public static function reconstitute(
        EmailOtpChallengeId $id,
        UuidPublicId $publicId,
        EmailAddress $emailAddress,
        string $otpHash,
        OtpPurpose $purpose,
        DateTimeImmutable $expiresAtUtc,
        int $attemptCount,
        int $maximumAttempts,
        ?DateTimeImmutable $consumedAtUtc,
        ?DateTimeImmutable $invalidatedAtUtc,
        ?string $requestedIpAddress,
        ?string $requestedUserAgent,
        DateTimeImmutable $createdAtUtc,
    ): self {
        if ($attemptCount < 0 || $attemptCount > $maximumAttempts) {
            throw InvalidIdentityValue::for('AttemptCount', (string) $attemptCount);
        }

        return new self(
            id: $id,
            publicId: $publicId,
            emailAddress: $emailAddress,
            otpHash: trim($otpHash),
            purpose: $purpose,
            expiresAtUtc: $expiresAtUtc,
            attemptCount: $attemptCount,
            maximumAttempts: $maximumAttempts,
            consumedAtUtc: $consumedAtUtc,
            invalidatedAtUtc: $invalidatedAtUtc,
            requestedIpAddress: self::nullableTrim($requestedIpAddress),
            requestedUserAgent: self::nullableTrim($requestedUserAgent),
            createdAtUtc: $createdAtUtc,
        );
    }

    public function assignId(EmailOtpChallengeId $id): void
    {
        if ($this->id !== null) {
            throw InvalidIdentityValue::for('EmailOtpChallengeId', 'already assigned');
        }

        $this->id = $id;
    }

    public function id(): ?EmailOtpChallengeId
    {
        return $this->id;
    }

    public function publicId(): UuidPublicId
    {
        return $this->publicId;
    }

    public function emailAddress(): EmailAddress
    {
        return $this->emailAddress;
    }

    public function otpHash(): string
    {
        return $this->otpHash;
    }

    public function purpose(): OtpPurpose
    {
        return $this->purpose;
    }

    public function expiresAtUtc(): DateTimeImmutable
    {
        return $this->expiresAtUtc;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    public function consumedAtUtc(): ?DateTimeImmutable
    {
        return $this->consumedAtUtc;
    }

    public function invalidatedAtUtc(): ?DateTimeImmutable
    {
        return $this->invalidatedAtUtc;
    }

    public function requestedIpAddress(): ?string
    {
        return $this->requestedIpAddress;
    }

    public function requestedUserAgent(): ?string
    {
        return $this->requestedUserAgent;
    }

    public function createdAtUtc(): DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAtUtc;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAtUtc !== null;
    }

    public function isInvalidated(): bool
    {
        return $this->invalidatedAtUtc !== null;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return !$this->isExpired($now)
            && !$this->isConsumed()
            && !$this->isInvalidated();
    }

    public function canAttempt(DateTimeImmutable $now): bool
    {
        return $this->isActive($now) && $this->attemptCount < $this->maximumAttempts;
    }

    public function incrementAttempt(DateTimeImmutable $now): void
    {
        $this->assertVerifiable($now);

        if ($this->attemptCount >= $this->maximumAttempts) {
            throw OtpAttemptsExceeded::create();
        }

        ++$this->attemptCount;
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->assertVerifiable($now);

        if ($this->attemptCount >= $this->maximumAttempts) {
            throw OtpAttemptsExceeded::create();
        }

        $this->consumedAtUtc = $now;
    }

    public function invalidate(DateTimeImmutable $now): void
    {
        if ($this->isConsumed() || $this->isInvalidated()) {
            return;
        }

        $this->invalidatedAtUtc = $now;
    }

    /**
     * Records a failed verification attempt after hash mismatch.
     */
    public function registerFailedAttempt(DateTimeImmutable $now): void
    {
        $this->incrementAttempt($now);

        if ($this->attemptCount >= $this->maximumAttempts) {
            throw OtpAttemptsExceeded::create();
        }

        throw OtpInvalid::create();
    }

    private function assertVerifiable(DateTimeImmutable $now): void
    {
        if ($this->isInvalidated()) {
            throw OtpChallengeInvalidated::create();
        }

        if ($this->isConsumed()) {
            throw OtpChallengeConsumed::create();
        }

        if ($this->isExpired($now)) {
            throw OtpChallengeExpired::create();
        }
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
