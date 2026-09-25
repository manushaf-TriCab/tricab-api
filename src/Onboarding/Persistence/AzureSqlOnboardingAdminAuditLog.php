<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;

/**
 * Writes `[audit].[OnboardingAdminEvents]`, on its own (refusals) or inside a
 * caller's transaction (everything else, through {@see self::appendWithin()}).
 */
final class AzureSqlOnboardingAdminAuditLog implements OnboardingAdminAuditLog
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function append(ActorContext $actor, OnboardingAdminEvent $event): void
    {
        self::appendWithin($this->connection, $actor, $event);
    }

    public static function appendWithin(SqlConnection $connection, ActorContext $actor, OnboardingAdminEvent $event): void
    {
        $connection->execute(
            <<<'SQL'
            INSERT INTO [audit].[OnboardingAdminEvents] (
                [ActorUserId], [SubjectUserId], [EventType], [TargetPublicId], [Outcome],
                [EventSummary], [DetailsJson], [PermissionCode],
                [RequestCorrelationId], [IpAddress], [UserAgent]
            ) VALUES (
                :actorUserId, :subjectUserId, :eventType, TRY_CONVERT(UNIQUEIDENTIFIER, :targetPublicId), :outcome,
                :summary, :detailsJson, :permissionCode,
                :correlationId, :ipAddress, :userAgent
            )
            SQL,
            [
                ':actorUserId' => $actor->userId,
                ':subjectUserId' => $event->subjectUserId,
                ':eventType' => $event->eventType,
                ':targetPublicId' => $event->targetPublicId,
                ':outcome' => $event->outcome,
                ':summary' => $event->summary,
                ':detailsJson' => $event->detailsJson(),
                ':permissionCode' => $event->permissionCode,
                ':correlationId' => $actor->correlationId === null ? null : mb_substr($actor->correlationId, 0, 100),
                ':ipAddress' => $actor->ipAddress === null ? null : mb_substr($actor->ipAddress, 0, 64),
                ':userAgent' => $actor->userAgent === null ? null : mb_substr($actor->userAgent, 0, 500),
            ],
        );
    }
}
