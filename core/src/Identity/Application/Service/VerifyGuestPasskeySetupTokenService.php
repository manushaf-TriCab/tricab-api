<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\PasskeyRegistrationSessionRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyRegistrationSession;
use TriCab\Core\Identity\Domain\Service\PasskeyTokenHasher;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class VerifyGuestPasskeySetupTokenService
{
    public function __construct(
        private readonly GuestPasskeySetupTokenRepository $tokens,
        private readonly PasskeyRegistrationSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly PasskeyAuthRateLimiter $rateLimiter,
        private readonly int $sessionTtlSeconds = 300,
        private readonly Clock $clock = new SystemClock(),
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    /**
     * @return array{registrationSessionToken: string, expiresIn: int}
     */
    public function execute(string $rawToken, ?string $clientIp = null): array
    {
        $this->rateLimiter->assertVerifyAllowed($clientIp);
        $token = $this->tokens->findByTokenHash(PasskeyTokenHasher::hash($rawToken));
        if ($token === null) {
            throw PasskeyAuthFailed::setupTokenInvalid();
        }
        $now = $this->clock->now();
        $token->assertUsable($now);

        $user = $this->users->findById($token->userId());
        if ($user === null) {
            throw PasskeyAuthFailed::setupTokenInvalid();
        }
        $user->assertAuthenticationAllowed();

        $rawSession = PasskeyTokenHasher::randomToken();
        $session = new PasskeyRegistrationSession(
            id: null,
            publicId: $this->uuids->generate(),
            userId: $token->userId(),
            setupTokenPublicId: $token->publicId(),
            sessionTokenHash: PasskeyTokenHasher::hash($rawSession),
            expiresAtUtc: $now->add(new DateInterval('PT' . $this->sessionTtlSeconds . 'S')),
            consumedAtUtc: null,
            createdAtUtc: $now,
        );
        $this->sessions->save($session);

        return [
            'registrationSessionToken' => $rawSession,
            'expiresIn' => $this->sessionTtlSeconds,
        ];
    }
}
