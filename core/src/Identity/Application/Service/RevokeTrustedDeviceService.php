<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Contract\TrustedDeviceRepository;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceNotFound;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class RevokeTrustedDeviceService
{
    public function __construct(
        private readonly TrustedDeviceRepository $devices,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function revokeByPublicIdForUser(string $publicId, UserId $userId): void
    {
        $revoked = $this->devices->revokeByPublicIdForUser(
            UuidPublicId::fromString($publicId),
            $userId,
        );

        if (!$revoked) {
            throw TrustedDeviceNotFound::create();
        }
    }

    public function revokeActiveForUserAndInstallation(UserId $userId, string $installationId): void
    {
        $device = $this->devices->findActiveByUserAndInstallation(
            $userId,
            UuidPublicId::fromString($installationId),
        );

        if ($device === null) {
            return;
        }

        $device->revoke($this->clock->now());
        $this->devices->save($device);
    }

    public function revokeCurrent(
        UserId $userId,
        string $publicId,
        ?string $installationId = null,
    ): void {
        $device = $this->devices->findByPublicId(UuidPublicId::fromString($publicId));
        if ($device === null || !$device->userId()->equals($userId)) {
            throw TrustedDeviceNotFound::create();
        }

        if ($installationId !== null && $installationId !== '') {
            $installation = UuidPublicId::fromString($installationId);
            if (!$device->installationId()->equals($installation)) {
                throw TrustedDeviceNotFound::create();
            }
        }

        if ($device->isRevoked()) {
            return;
        }

        $device->revoke($this->clock->now());
        $this->devices->save($device);
    }
}
