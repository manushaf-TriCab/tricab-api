<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserManagementAuditEntry
{
    /**
     * @param array<string, mixed>|null $previousValues
     * @param array<string, mixed>|null $newValues
     */
    public function __construct(
        public string $publicId,
        public string $eventType,
        public string $eventSummary,
        public string $actorPublicId,
        public ?string $targetUserPublicId,
        public ?string $targetRoleCode,
        public ?string $targetPermissionCode,
        public ?array $previousValues,
        public ?array $newValues,
        public string $createdAtUtc,
        public ?string $correlationId = null,
        public ?string $actorDisplayName = null,
        public ?string $actorEmailAddress = null,
        public ?string $targetUserDisplayName = null,
        public ?string $targetUserEmailAddress = null,
    ) {
    }
}
