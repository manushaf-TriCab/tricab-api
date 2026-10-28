<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics;

/**
 * User activity analytics: web and mobile activity signals aggregated for the admin dashboard.
 * Dashboard responses carry counts only, never user identities.
 */
final class Module
{
    public const NAME = 'Analytics';

    /** Read aggregate activity analytics (admin dashboard panels). */
    public const PERMISSION_READ = 'ANALYTICS.READ';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
    ];
}
