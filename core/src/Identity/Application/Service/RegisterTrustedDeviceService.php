<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use TriCab\Core\Identity\Application\DTO\RegisteredTrustedDevice;
use TriCab\Core\Identity\Contract\TrustedDeviceRepository;
use TriCab\Core\Identity\Domain\Entity\TrustedDevice;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceIneligible;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\Service\TrustedDeviceCredentialGenerator;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Registers a trusted mobile device and returns the raw credential once.
 */
final class RegisterTrustedDeviceService
{
    public const DEFAULT_TTL_DAYS = 90;

    public function __construct(
        private readonly TrustedDeviceRepository $devices,
        private readonly TrustedDeviceCredentialGenerator $credentials = new TrustedDeviceCredentialGenerator(),
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
        private readonly Clock $clock = new SystemClock(),
        private readonly int $ttlDays = self::DEFAULT_TTL_DAYS,
    ) {
        if ($this->ttlDays < 1 || $this->ttlDays > 365) {
            throw new \InvalidArgumentException('TRUSTED_DEVICE_TTL_DAYS must be between 1 and 365.');
        }
    }

    public function execute(
        User $user,
        string $installationId,
        string $platform,
        ?string $deviceName = null,
        ?string $createdIp = null,
    ): RegisteredTrustedDevice {
        $user->assertAuthenticationAllowed();
        if (!$user->hasPermission(ProtectedIdentityCatalog::accountLogin())) {
            throw TrustedDeviceIneligible::missingLoginPermission();
        }

        $now = $this->clock->now();
        $installation = UuidPublicId::fromString($installationId);

        $existing = $this->devices->findActiveByUserAndInstallation($user->id(), $installation);
        if ($existing !== null) {
            $existing->revoke($now);
            $this->devices->save($existing);
        }

        $rawCredential = $this->credentials->generateRaw();
        $device = TrustedDevice::register(
            publicId: $this->uuidGenerator->generate(),
            userId: $user->id(),
            credentialHash: $this->credentials->hash($rawCredential),
            installationId: $installation,
            platform: $platform,
            createdAtUtc: $now,
            expiresAtUtc: $now->add(new DateInterval('P' . $this->ttlDays . 'D')),
            deviceName: $deviceName,
            createdIp: $createdIp,
        );
        $this->devices->save($device);

        return new RegisteredTrustedDevice(
            publicId: $device->publicId()->toString(),
            credential: $rawCredential,
            expiresAtUtc: $device->expiresAtUtc()->format(DATE_ATOM),
            credentialVersion: $device->credentialVersion(),
        );
    }
}
