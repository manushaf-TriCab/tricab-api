<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\EmailOtpChallengeId;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class InMemoryEmailOtpChallengeRepository implements EmailOtpChallengeRepository
{
    /** @var array<int, EmailOtpChallenge> */
    private array $challenges = [];

    private int $nextId = 1;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function save(EmailOtpChallenge $challenge): void
    {
        if ($challenge->id() === null) {
            $challenge->assignId(EmailOtpChallengeId::fromInt($this->nextId));
            ++$this->nextId;
        }

        $id = $challenge->id();
        assert($id instanceof EmailOtpChallengeId);
        $this->challenges[$id->toInt()] = $challenge;
    }

    public function findById(EmailOtpChallengeId $id): ?EmailOtpChallenge
    {
        return $this->challenges[$id->toInt()] ?? null;
    }

    public function findByPublicId(UuidPublicId $publicId): ?EmailOtpChallenge
    {
        foreach ($this->challenges as $challenge) {
            if ($challenge->publicId()->equals($publicId)) {
                return $challenge;
            }
        }

        return null;
    }

    public function findLatestActiveByEmailAndPurpose(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
    ): ?EmailOtpChallenge {
        $now = $this->clock->now();
        $latest = null;

        foreach ($this->challenges as $challenge) {
            if (
                !$challenge->emailAddress()->equals($emailAddress)
                || !$challenge->purpose()->equals($purpose)
                || !$challenge->isActive($now)
            ) {
                continue;
            }

            if (
                $latest === null
                || $challenge->createdAtUtc() > $latest->createdAtUtc()
            ) {
                $latest = $challenge;
            }
        }

        return $latest;
    }

    public function invalidateActiveChallenges(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
    ): void {
        $now = $this->clock->now();

        foreach ($this->challenges as $challenge) {
            if (
                $challenge->emailAddress()->equals($emailAddress)
                && $challenge->purpose()->equals($purpose)
                && $challenge->isActive($now)
            ) {
                $challenge->invalidate($now);
            }
        }
    }

    /** @return list<EmailOtpChallenge> */
    public function all(): array
    {
        return array_values($this->challenges);
    }
}
