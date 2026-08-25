<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\DTO\IssuedGuestSession;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class CompletePasskeyAuthenticationService
{
    public function __construct(
        private readonly PasskeyChallengeRepository $challenges,
        private readonly UserPasskeyRepository $passkeys,
        private readonly UserRepository $users,
        private readonly PasskeyCeremony $ceremony,
        private readonly PasskeyRpConfig $rp,
        private readonly IssueGuestSessionService $issueSession,
        private readonly PasskeyAuthRateLimiter $rateLimiter,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function execute(
        string $challengeId,
        string $assertionJson,
        string $clientOrigin,
        ?string $clientIp = null,
        ?string $userAgent = null,
    ): IssuedGuestSession {
        $this->rateLimiter->assertCeremonyAllowed($clientIp);
        $now = $this->clock->now();
        $challenge = $this->challenges->findByPublicId(UuidPublicId::fromString($challengeId));
        if ($challenge === null) {
            throw PasskeyAuthFailed::challengeInvalid();
        }
        $challenge->assertUsable($now, PasskeyChallengePurpose::authentication());

        $decoded = json_decode($assertionJson, true);
        $idRaw = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $credentialId = is_string($idRaw) ? $idRaw : '';
        if ($credentialId === '') {
            throw PasskeyAuthFailed::unknownCredential();
        }
        $passkey = $this->passkeys->findByCredentialId($credentialId);
        if ($passkey === null) {
            throw PasskeyAuthFailed::unknownCredential();
        }
        if (!$passkey->isActive()) {
            throw PasskeyAuthFailed::inactiveCredential();
        }

        $user = $this->users->findById($passkey->userId());
        if ($user === null) {
            throw PasskeyAuthFailed::unknownCredential();
        }
        $user->assertAuthenticationAllowed();

        $assertion = $this->ceremony->completeAuthentication(
            $this->rp,
            $challenge->challengeBinary(),
            $passkey->credentialId(),
            $passkey->publicKey(),
            $passkey->signCount(),
            $clientOrigin,
            $assertionJson,
        );
        if (!$assertion->userVerified) {
            throw PasskeyAuthFailed::userVerificationRequired();
        }

        $nextCount = $assertion->signCount > 0 ? $assertion->signCount : $passkey->signCount();
        $passkey->recordUse($now, $nextCount);
        $this->passkeys->save($passkey);
        $challenge->consume($now);
        $this->challenges->save($challenge);

        return $this->issueSession->execute(
            user: $user,
            createdIpAddress: $clientIp,
            createdUserAgent: $userAgent,
            authProvider: 'passkey',
        );
    }
}
