<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes;

/**
 * Cable routes: a read-only view of how each finished cable is made (machine steps, materials, factory map),
 * served from a SQLite store that core/python/cable_routes builds from the Dynamics extract.
 */
final class Module
{
    public const NAME = 'CableRoutes';

    /** See the cable routes pages and their data. */
    public const PERMISSION_READ = 'CABLE_ROUTES.READ';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
    ];
}
