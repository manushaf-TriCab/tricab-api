<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * The applications and standards Evaluate can name (C4). The codes are CableFit Pro's `ApplicationType` and
 * `SuitabilityStandard` (web `domain/types.ts`); OTHER is an application described in free text.
 */
final class ResearchApplication
{
    public const OTHER = 'OTHER';

    /** The one application the browser rule check has rules for; its result is sent with the request. */
    public const REELING_UNREELING = 'REELING_UNREELING';

    public const LABELS = [
        'FIXED' => 'Fixed',
        'FLEXING' => 'Flexing',
        self::REELING_UNREELING => 'Reeling & Unreeling',
        'TORSION' => 'Torsion',
        'SELF_HANGING' => 'Self-hanging',
        'DRAG_CHAIN' => 'Drag Chain',
        'ELEVATOR' => 'Elevator',
        'CRANES' => 'Cranes',
        'PONTOON_FLOATING' => 'Pontoon & Floating',
        'COLD_TEMP' => 'Cold Temp',
    ];

    public const STANDARDS = [
        'AS_NZS_2802' => 'AS/NZS 2802',
    ];

    public static function isCode(string $code): bool
    {
        return $code === self::OTHER || isset(self::LABELS[$code]);
    }

    public static function standardLabel(?string $code): string
    {
        return $code === null ? 'none (minimum baseline)' : (self::STANDARDS[$code] ?? $code);
    }
}
