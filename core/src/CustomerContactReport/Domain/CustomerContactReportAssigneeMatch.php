<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

/**
 * Current-assignee check shared by completion.
 *
 * User id wins when it matches. Login and email are the same fallback READ
 * uses, so a caller who can see the report as assignee can complete it.
 * An empty login never matches a blank assignee.
 */
final class CustomerContactReportAssigneeMatch
{
    public static function isCurrent(
        int $actorUserId,
        string $actorEmail,
        ?int $assignedToUserId,
        ?string $assignedTo,
        ?string $assignedToEmail,
    ): bool {
        if ($actorUserId > 0 && $assignedToUserId !== null && $assignedToUserId === $actorUserId) {
            return true;
        }

        $mail = strtolower(trim($actorEmail));
        if ($mail === '') {
            return false;
        }

        return strtolower(trim($assignedTo ?? '')) === $mail
            || strtolower(trim($assignedToEmail ?? '')) === $mail;
    }
}
