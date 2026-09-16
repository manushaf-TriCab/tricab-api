<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\TrustedDeviceRepository;
use TriCab\Core\Identity\Domain\Entity\TrustedDevice;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\TrustedDeviceId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class InMemoryTrustedDeviceRepository implements TrustedDeviceRepository
{
    /** @var array<int, TrustedDevice> */
    private array $devices = [];

    private int $nextId = 1;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function save(TrustedDevice $device): void
    {
        if ($device->id() === null) {
            $device->assignId(TrustedDeviceId::fromInt($this->nextId));
            ++$this->nextId;
        }

        $id = $device->id();
        assert($id instanceof TrustedDeviceId);
        $this->devices[$id->toInt()] = $device;
    }

    public function findByPublicId(UuidPublicId $publicId): ?TrustedDevice
    {
        foreach ($this->devices as $device) {
            if ($device->publicId()->equals($publicId)) {
                return $device;
            }
        }

        return null;
    }

    public function findByCredentialHash(SessionTokenHash $credentialHash): ?TrustedDevice
    {
        foreach ($this->devices as $device) {
            if ($device->credentialHash()->equals($credentialHash)) {
                return $device;
            }
        }

        return null;
    }

    public function findActiveByUserAndInstallation(UserId $userId, UuidPublicId $installationId): ?TrustedDevice
    {
        $now = $this->clock->now();
        foreach ($this->devices as $device) {
            if (
                $device->userId()->equals($userId)
                && $device->installationId()->equals($installationId)
                && $device->isActive($now)
            ) {
                return $device;
            }
        }

        return null;
    }

    public function revokeByPublicIdForUser(UuidPublicId $publicId, UserId $userId): bool
    {
        $device = $this->findByPublicId($publicId);
        if ($device === null || !$device->userId()->equals($userId)) {
            return false;
        }

        $device->revoke($this->clock->now());
        $this->save($device);

        return true;
    }

    /** @return list<TrustedDevice> */
    public function all(): array
    {
        return array_values($this->devices);
    }
}
