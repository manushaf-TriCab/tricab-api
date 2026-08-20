<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

final class StaffVerification
{
    public const WORDPRESS_ONLY = 'WORDPRESS_ONLY';
    public const WORDPRESS_AND_EMPLOYEE = 'WORDPRESS_AND_EMPLOYEE';

    private function __construct()
    {
    }
}
