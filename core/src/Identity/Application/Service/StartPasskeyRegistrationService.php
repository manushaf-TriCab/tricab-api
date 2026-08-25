<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Contract\PasskeyRegistrationSessionRepository;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyChallenge;
use TriCab\Core\Identity\Domain\Service\PasskeyTokenHasher;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Identity\Domain\ValueObject\WebAuthnUserHandle;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class StartPasskeyRegistrationService
{
    public function __construct(
        private readonly PasskeyRegistrationSessionRepository $sessions,
        private readonly PasskeyChallengeRepository $challenges,
        private readonly UserPasskeyRepository $passkeys,
        private readonly UserRepository $users,
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
    public function execute(string $registrationSessionToken, ?string $clientIp = null): array
    {
        $this->rateLimiter->assertCeremonyAllowed($clientIp);
        $session = $this->sessions->findBySessionTokenHash(PasskeyTokenHasher::hash($registrationSessionToken));
        if ($session === null) {
            throw PasskeyAuthFailed::registrationSessionInvalid();
        }
        $now = $this->clock->now();
        $session->assertUsable($now);

        $user = $this->users->findById($session->userId());
        if ($user === null) {
            throw PasskeyAuthFailed::registrationSessionInvalid();
        }
        $user->assertAuthenticationAllowed();

        $handle = $this->passkeys->findHandleByUserId($user->id());
        if ($handle === null) {
            $handle = WebAuthnUserHandle::generate();
            $this->passkeys->saveHandle($user->id(), $handle);
        }

        $exclude = [];
        foreach ($this->passkeys->listActiveByUserId($user->id()) as $existing) {
            $exclude[] = $existing->credentialId();
        }

        $options = $this->ceremony->registrationOptions(
            $this->rp,
            $handle->toBinary(),
            $user->emailAddress()->toString(),
            $user->displayName(),
            $exclude,
        );

        $challenge = new PasskeyChallenge(
            id: null,
            publicId: $this->uuids->generate(),
            userId: $user->id(),
            registrationSessionPublicId: $session->publicId(),
            purpose: PasskeyChallengePurpose::registration(),
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
