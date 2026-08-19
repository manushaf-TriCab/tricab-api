<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

final class StaffMigrationEligibility
{
    public const SAFE_NEW_STAFF_USER = 'SAFE_NEW_STAFF_USER';
    public const MISSING_EMAIL = 'MISSING_EMAIL';
    public const INVALID_EMAIL = 'INVALID_EMAIL';
    public const DUPLICATE_EMAIL = 'DUPLICATE_EMAIL';
    public const EXISTING_USER_MATCH = 'EXISTING_USER_MATCH';
    public const ROLE_REVIEW_REQUIRED = 'ROLE_REVIEW_REQUIRED';
    public const AMBIGUOUS_STAFF = 'AMBIGUOUS_STAFF';
    public const OTHER_BLOCKING_CONFLICT = 'OTHER_BLOCKING_CONFLICT';

    private function __construct()
    {
    }
}
