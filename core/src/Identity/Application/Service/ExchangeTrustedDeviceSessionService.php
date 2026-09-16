<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\DTO\ExchangedTrustedDeviceSession;
use TriCab\Core\Identity\Application\DTO\RegisteredTrustedDevice;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Contract\TrustedDeviceRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceExpired;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceIneligible;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceInvalidCredential;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceNotFound;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceRevoked;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\Service\TrustedDeviceCredentialGenerator;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class ExchangeTrustedDeviceSessionService
{
    public function __construct(
        private readonly TrustedDeviceRepository $devices,
        private readonly UserRepository $users,
        private readonly IssueGuestSessionService $issueGuestSession,
        private readonly GuestLoginEligibilityService $eligibility,
        private readonly TrustedDeviceCredentialGenerator $credentials = new TrustedDeviceCredentialGenerator(),
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function execute(
        string $publicId,
        string $rawCredential,
        string $installationId,
        ?string $clientIp = null,
        ?string $userAgent = null,
    ): ExchangedTrustedDeviceSession {
        $now = $this->clock->now();
        $devicePublicId = UuidPublicId::fromString($publicId);
        $installation = UuidPublicId::fromString($installationId);

        $device = $this->devices->findByPublicId($devicePublicId);
        if ($device === null) {
            throw TrustedDeviceNotFound::create();
        }

        if (!$device->installationId()->equals($installation)) {
            throw TrustedDeviceInvalidCredential::create();
        }

        if ($device->isRevoked()) {
            throw TrustedDeviceRevoked::create();
        }

        if ($device->isExpired($now)) {
            throw TrustedDeviceExpired::create();
        }

        $match = $device->matchesCredential($this->credentials->hash($rawCredential), $now);
        if ($match === null) {
            throw TrustedDeviceInvalidCredential::create();
        }

        $user = $this->users->findById($device->userId());
        if ($user === null) {
            throw TrustedDeviceIneligible::create();
        }

        try {
            $this->eligibility->assertEligible($user->emailAddress());
            $user->assertAuthenticationAllowed();
        } catch (GuestLoginNotEligible|AuthenticationNotAllowed) {
            throw TrustedDeviceIneligible::create();
        }

        if (!$user->hasPermission(ProtectedIdentityCatalog::accountLogin())) {
            throw TrustedDeviceIneligible::create();
        }

        $rawReplacement = $this->credentials->generateRaw();
        $device->rotateCredential(
            newCredentialHash: $this->credentials->hash($rawReplacement),
            now: $now,
            preserveMatchedPrevious: $match === 'previous',
        );
        $device->touchLastUsed($now, $clientIp);
        $this->devices->save($device);

        $session = $this->issueGuestSession->execute(
            user: $user,
            createdIpAddress: $clientIp,
            createdUserAgent: $userAgent,
            authProvider: 'trusted-device',
        );

        return new ExchangedTrustedDeviceSession(
            session: $session,
            deviceCredential: new RegisteredTrustedDevice(
                publicId: $device->publicId()->toString(),
                credential: $rawReplacement,
                expiresAtUtc: $device->expiresAtUtc()->format(DATE_ATOM),
                credentialVersion: $device->credentialVersion(),
            ),
        );
    }
}
