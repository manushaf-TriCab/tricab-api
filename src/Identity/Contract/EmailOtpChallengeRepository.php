<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailOtpChallengeId;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

interface EmailOtpChallengeRepository
{
    public function save(EmailOtpChallenge $challenge): void;

    public function findById(EmailOtpChallengeId $id): ?EmailOtpChallenge;

    public function findByPublicId(UuidPublicId $publicId): ?EmailOtpChallenge;

    public function findLatestActiveByEmailAndPurpose(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
    ): ?EmailOtpChallenge;

    /**
     * Invalidates every active (unconsumed, unexpired, not invalidated) challenge
     * for the email + purpose pair.
     */
    public function invalidateActiveChallenges(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
    ): void;
}
