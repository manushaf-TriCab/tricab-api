<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * What an HR action was, as `[audit].[OnboardingAdminEvents].[EventType]`
 * records it.
 *
 * A closed list rather than free text so a report can group on it and a typo
 * in one service cannot invent a category nobody queries for. Phase 4 adds its
 * codes here as each action lands.
 */
final class AdminEventType
{
    public const ALLOCATION_ALLOCATED = 'allocation.allocated';
    public const ALLOCATION_WITHDRAWN = 'allocation.withdrawn';
    public const ALLOCATION_DUE_DATE_CHANGED = 'allocation.due_date_changed';
    /** A whole allocation save that was refused before anything was written. */
    public const ALLOCATION_REFUSED = 'allocation.refused';

    public const ALL = [
        self::ALLOCATION_ALLOCATED,
        self::ALLOCATION_WITHDRAWN,
        self::ALLOCATION_DUE_DATE_CHANGED,
        self::ALLOCATION_REFUSED,
    ];

    public static function assertKnown(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw InvalidOnboardingValue::forOneOf('AdminEventType', $value, self::ALL);
        }

        return $value;
    }
}
