<?php

declare(strict_types=1);

namespace TriCab\Core\Tsd;

/**
 * Marker for the TSD bounded context — the technical-sales admin module that
 * will hold the NPR (New Product Request) dashboard and CableFit Pro.
 *
 * Only the permission exists so far. No role holds it and no route checks it
 * (plan NPR-TSD-MIGRATION-PLAN, T0).
 */
final class Module
{
    public const NAME = 'TSD';

    /** Seed `ModuleCode` for every permission below. */
    public const MODULE_CODE = 'tsd';

    /** Open the TSD admin module. */
    public const PERMISSION_ACCESS = 'TSD.ACCESS';

    /** Every permission the module defines. */
    public const PERMISSIONS = [
        self::PERMISSION_ACCESS,
    ];
}
