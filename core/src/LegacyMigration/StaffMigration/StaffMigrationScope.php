<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

final class StaffMigrationScope
{
    public const STAFF_ONLY = 'STAFF_ONLY';

    /** @var list<string> */
    public const EXCLUDED_CUSTOMER_ROLES = ['customer', 'electrician', 'distributor'];

    private function __construct()
    {
    }
}
