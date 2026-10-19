<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit;

/**
 * Marker for the CableFit bounded context: cable-design candidates built by
 * TSD, optionally seeded from the Section 5 answers of an NPR request.
 *
 * CableFit reads NPR data only through {@see \TriCab\Core\Npr\NprRequestReader}.
 * It never evaluates, scores or approves a candidate.
 */
final class Module
{
    public const NAME = 'CableFit';

    /** Seed `ModuleCode` for every permission below. */
    public const MODULE_CODE = 'tsd';

    /** Build, view and save CableFit candidates. */
    public const PERMISSION_USE = 'TSD.CABLEFIT.USE';

    /** Research-only use of CableFit (no NPR hand-off). */
    public const PERMISSION_RESEARCH = 'TSD.CABLEFIT.RESEARCH';

    /** Every permission the module defines. */
    public const PERMISSIONS = [
        self::PERMISSION_USE,
        self::PERMISSION_RESEARCH,
    ];
}
