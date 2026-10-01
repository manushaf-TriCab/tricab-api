<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

/**
 * One approval is one transaction. A person may hold both coordinator and
 * approver roles and still decide someone else's remedy. They cannot decide
 * the remedy they requested.
 *
 * User id wins when both sides have one. Login and email are the same
 * fallback used for the recorded approver.
 */
final class CustomerContactReportApprovalSeparation
{
    public static function samePerson(
        int $leftUserId,
        string $leftEmail,
        ?int $rightUserId,
        string $rightLogin,
        string $rightEmail,
    ): bool {
        return CustomerContactReportAssigneeMatch::isCurrent(
            $leftUserId,
            $leftEmail,
            $rightUserId,
            $rightLogin,
            $rightEmail,
        );
    }
}
