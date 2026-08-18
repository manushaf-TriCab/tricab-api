<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditWrite;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;

/**
 * @phpstan-import-type JsonMap from UserManagementAuditWrite
 */
final class UserManagementAuditRecorder
{
    public function __construct(
        private readonly UserManagementAuditRepository $audit,
    ) {
    }

    /**
     * @param JsonMap|null $previousValues
     * @param JsonMap|null $newValues
     */
    public function record(
        ActorContext $actor,
        string $eventType,
        string $eventSummary,
        ?int $targetUserId = null,
        ?int $targetRoleId = null,
        ?int $targetPermissionId = null,
        ?array $previousValues = null,
        ?array $newValues = null,
    ): void {
        $type = UserManagementEventType::fromString($eventType);

        $this->audit->append(new UserManagementAuditWrite(
            eventType: $type->toString(),
            eventSummary: $eventSummary,
            actorUserId: $actor->userId,
            targetUserId: $targetUserId,
            targetRoleId: $targetRoleId,
            targetPermissionId: $targetPermissionId,
            previousValues: $this->redact($previousValues),
            newValues: $this->redact($newValues),
            correlationId: $actor->correlationId,
            ipAddress: $actor->ipAddress,
            userAgent: $actor->userAgent,
        ));
    }

    public function actorUserId(ActorContext $actor): UserId
    {
        return UserId::fromInt($actor->userId);
    }

    public static function requireDisplayName(string $displayName): string
    {
        $normalized = trim($displayName);
        if ($normalized === '') {
            throw InvalidIdentityValue::for('DisplayName', $displayName);
        }

        return $normalized;
    }

    /**
     * @param JsonMap|null $values
     * @return JsonMap|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = [];
        foreach ($values as $key => $value) {
            $lower = strtolower((string) $key);
            if (
                str_contains($lower, 'token')
                || str_contains($lower, 'otp')
                || str_contains($lower, 'secret')
                || str_contains($lower, 'password')
                || str_contains($lower, 'authorization')
                || str_contains($lower, 'hash')
                || str_contains($lower, 'jwt')
            ) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }
}
