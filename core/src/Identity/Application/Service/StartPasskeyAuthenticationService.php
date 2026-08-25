<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyChallenge;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class StartPasskeyAuthenticationService
{
    public function __construct(
        private readonly PasskeyChallengeRepository $challenges,
        private readonly PasskeyCeremony $ceremony,
        private readonly PasskeyRpConfig $rp,
        private readonly PasskeyAuthRateLimiter $rateLimiter,
        private readonly int $challengeTtlSeconds = 300,
        private readonly Clock $clock = new SystemClock(),
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    /**
     * @return array{challengeId: string, publicKey: array<string, mixed>}
     */
    public function execute(?string $clientIp = null): array
    {
        $this->rateLimiter->assertCeremonyAllowed($clientIp);
        $now = $this->clock->now();
        $options = $this->ceremony->authenticationOptions($this->rp);
        $challenge = new PasskeyChallenge(
            id: null,
            publicId: $this->uuids->generate(),
            userId: null,
            registrationSessionPublicId: null,
            purpose: PasskeyChallengePurpose::authentication(),
            challengeBinary: $options->challengeBinary,
            expiresAtUtc: $now->add(new DateInterval('PT' . $this->challengeTtlSeconds . 'S')),
            consumedAtUtc: null,
            createdAtUtc: $now,
        );
        $this->challenges->save($challenge);

        return [
            'challengeId' => $challenge->publicId()->toString(),
            'publicKey' => $options->publicKey,
        ];
    }
}
