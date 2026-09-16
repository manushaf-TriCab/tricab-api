<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\TrustedDevice;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

interface TrustedDeviceRepository
{
    public function save(TrustedDevice $device): void;

    public function findByPublicId(UuidPublicId $publicId): ?TrustedDevice;

    public function findByCredentialHash(SessionTokenHash $credentialHash): ?TrustedDevice;

    public function findActiveByUserAndInstallation(UserId $userId, UuidPublicId $installationId): ?TrustedDevice;

    /**
     * Soft-revokes the device when it belongs to the user. Idempotent when already revoked.
     */
    public function revokeByPublicIdForUser(UuidPublicId $publicId, UserId $userId): bool;
}
