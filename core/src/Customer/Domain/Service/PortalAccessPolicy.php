<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Service;

use TriCab\Core\Customer\Domain\Exception\InvalidPortalAccessTransition;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;

/**
 * Governs allowed transitions of a contact's customer-portal access status.
 *
 * Allowed transitions:
 *  - not-invited -> invited, declined
 *  - invited     -> active, declined, not-invited, suspended
 *  - active      -> suspended, declined
 *  - suspended   -> active, declined
 *  - declined    -> invited, not-invited
 *
 * Transitioning to the current status is always a no-op success (idempotent).
 */
final class PortalAccessPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        PortalAccessStatus::NOT_INVITED => [PortalAccessStatus::INVITED, PortalAccessStatus::DECLINED],
        PortalAccessStatus::INVITED => [
            PortalAccessStatus::ACTIVE,
            PortalAccessStatus::DECLINED,
            PortalAccessStatus::NOT_INVITED,
            PortalAccessStatus::SUSPENDED,
        ],
        PortalAccessStatus::ACTIVE => [PortalAccessStatus::SUSPENDED, PortalAccessStatus::DECLINED],
        PortalAccessStatus::SUSPENDED => [PortalAccessStatus::ACTIVE, PortalAccessStatus::DECLINED],
        PortalAccessStatus::DECLINED => [PortalAccessStatus::INVITED, PortalAccessStatus::NOT_INVITED],
    ];

    public function isTransitionAllowed(PortalAccessStatus $from, PortalAccessStatus $to): bool
    {
        if ($from->equals($to)) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$from->toString()] ?? [];

        return in_array($to->toString(), $allowed, true);
    }

    public function assertTransitionAllowed(PortalAccessStatus $from, PortalAccessStatus $to): void
    {
        if (!$this->isTransitionAllowed($from, $to)) {
            throw InvalidPortalAccessTransition::from($from->toString(), $to->toString());
        }
    }

    /**
     * Target status when granting portal access to a contact.
     */
    public function enableTarget(bool $activateImmediately): PortalAccessStatus
    {
        return $activateImmediately ? PortalAccessStatus::active() : PortalAccessStatus::invited();
    }

    /**
     * Target status when revoking portal access from a contact.
     */
    public function disableTarget(): PortalAccessStatus
    {
        return PortalAccessStatus::suspended();
    }
}
