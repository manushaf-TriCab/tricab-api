<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use DateTimeImmutable;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Persists a guest session for an already-authenticated user.
 * Session token hashing and JWT creation remain API infrastructure concerns.
 */
final class CreateGuestSessionService
{
    public const DEFAULT_TTL = 'PT24H';

    public function __construct(
        private readonly GuestSessionRepository $sessions,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
        private readonly Clock $clock = new SystemClock(),
        private readonly string $ttl = self::DEFAULT_TTL,
    ) {
    }

    public function execute(
        User $user,
        SessionTokenHash $sessionTokenHash,
        ?UuidPublicId $jwtId = null,
        ?DateTimeImmutable $issuedAtUtc = null,
        ?DateTimeImmutable $expiresAtUtc = null,
        ?string $createdIpAddress = null,
        ?string $createdUserAgent = null,
        ?UuidPublicId $publicId = null,
    ): GuestSession {
        $user->assertAuthenticationAllowed();

        $issuedAt = $issuedAtUtc ?? $this->clock->now();
        $expiresAt = $expiresAtUtc ?? $issuedAt->add(new DateInterval($this->ttl));

        $session = GuestSession::open(
            publicId: $publicId ?? $this->uuidGenerator->generate(),
            userId: $user->id(),
            sessionTokenHash: $sessionTokenHash,
            issuedAtUtc: $issuedAt,
            expiresAtUtc: $expiresAt,
            jwtId: $jwtId,
            createdIpAddress: $createdIpAddress,
            createdUserAgent: $createdUserAgent,
        );

        $this->sessions->save($session);

        return $session;
    }
}
