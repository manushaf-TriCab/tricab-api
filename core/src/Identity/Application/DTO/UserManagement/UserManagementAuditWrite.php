<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

/**
 * Internal write model for audit persistence. Values must already be redacted.
 *
 * @phpstan-type JsonMap array<string, scalar|array<int|string, scalar|null>|null>
 */
final readonly class UserManagementAuditWrite
{
    /**
     * @param JsonMap|null $previousValues
     * @param JsonMap|null $newValues
     */
    public function __construct(
        public string $eventType,
        public string $eventSummary,
        public int $actorUserId,
        public ?int $targetUserId,
        public ?int $targetRoleId,
        public ?int $targetPermissionId,
        /** @var JsonMap|null */
        public ?array $previousValues,
        /** @var JsonMap|null */
        public ?array $newValues,
        public ?string $correlationId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {
    }
}
