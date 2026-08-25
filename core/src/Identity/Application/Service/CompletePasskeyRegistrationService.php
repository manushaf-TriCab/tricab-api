<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\DTO\IssuedGuestSession;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Contract\PasskeyRegistrationSessionRepository;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\UserPasskey;
use TriCab\Core\Identity\Domain\Service\PasskeyExternalIdentityFactory;
use TriCab\Core\Identity\Domain\Service\PasskeyTokenHasher;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\PasskeyChallengePurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class CompletePasskeyRegistrationService
{
    public function __construct(
        private readonly PasskeyRegistrationSessionRepository $sessions,
        private readonly PasskeyChallengeRepository $challenges,
        private readonly GuestPasskeySetupTokenRepository $tokens,
        private readonly UserPasskeyRepository $passkeys,
        private readonly UserRepository $users,
        private readonly UserProvisioningRepository $provisioning,
        private readonly PasskeyCeremony $ceremony,
        private readonly PasskeyRpConfig $rp,
        private readonly IssueGuestSessionService $issueSession,
        private readonly PasskeyAuthRateLimiter $rateLimiter,
        private readonly Clock $clock = new SystemClock(),
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function execute(
        string $registrationSessionToken,
        string $challengeId,
        string $attestationJson,
        string $clientOrigin,
        ?string $clientIp = null,
        ?string $userAgent = null,
    ): IssuedGuestSession {
        $this->rateLimiter->assertCeremonyAllowed($clientIp);
        $now = $this->clock->now();

        $session = $this->sessions->findBySessionTokenHash(PasskeyTokenHasher::hash($registrationSessionToken));
        if ($session === null) {
            throw PasskeyAuthFailed::registrationSessionInvalid();
        }
        $session->assertUsable($now);

        $challenge = $this->challenges->findByPublicId(UuidPublicId::fromString($challengeId));
        if ($challenge === null) {
            throw PasskeyAuthFailed::challengeInvalid();
        }
        $challenge->assertUsable($now, PasskeyChallengePurpose::registration());
        $boundSession = $challenge->registrationSessionPublicId();
        if ($boundSession === null || !$boundSession->equals($session->publicId())) {
            throw PasskeyAuthFailed::challengeInvalid();
        }

        $user = $this->users->findById($session->userId());
        if ($user === null) {
            throw PasskeyAuthFailed::registrationSessionInvalid();
        }
        $user->assertAuthenticationAllowed();

        $handle = $this->passkeys->findHandleByUserId($user->id());
        if ($handle === null) {
            throw PasskeyAuthFailed::registrationSessionInvalid();
        }

        $attestation = $this->ceremony->completeRegistration(
            $this->rp,
            $challenge->challengeBinary(),
            $handle->toBinary(),
            $clientOrigin,
            $attestationJson,
        );
        if (!$attestation->userVerified) {
            throw PasskeyAuthFailed::userVerificationRequired();
        }
        if ($this->passkeys->findByCredentialId($attestation->credentialId) !== null) {
            throw PasskeyAuthFailed::duplicateCredential();
        }

        $this->passkeys->save(new UserPasskey(
            id: null,
            publicId: $this->uuids->generate(),
            userId: $user->id(),
            credentialId: $attestation->credentialId,
            publicKey: $attestation->publicKey,
            signCount: $attestation->signCount,
            transports: $attestation->transports,
            aaguid: $attestation->aaguid,
            deviceLabel: 'Passkey',
            createdAtUtc: $now,
            lastUsedAtUtc: $now,
            isActive: true,
        ));

        $challenge->consume($now);
        $this->challenges->save($challenge);
        $session->consume($now);
        $this->sessions->save($session);

        $setup = $this->tokens->findByPublicId($session->setupTokenPublicId());
        if ($setup !== null) {
            $setup->consume($now);
            $this->tokens->save($setup);
        }

        $this->provisioning->linkExternalIdentity($user, PasskeyExternalIdentityFactory::forHandle($handle));

        return $this->issueSession->execute(
            user: $user,
            createdIpAddress: $clientIp,
            createdUserAgent: $userAgent,
            authProvider: 'passkey',
        );
    }
}
