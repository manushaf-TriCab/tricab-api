<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

/**
 * Approved Staff Migration Batch 1 guard constants.
 */
final class StaffMigrationBatch1Policy
{
    public const SCOPE = StaffMigrationScope::STAFF_ONLY;
    public const EXPECTED_SAFE_COUNT = 617;
    public const EXPECTED_ADMIN_ASSIGNMENTS = 5;
    public const EXPECTED_EMPLOYEE_ASSIGNMENTS = 612;
    public const EXPECTED_EXISTING_USERS = 2;
    public const EXPECTED_MISSING_EMAIL = 129;
    public const EXPECTED_BLOCKED_ROLE = 55;
    public const BLOCKED_ROLE_SUMMARY_KEY = 'wouldBlockRoleConflict';
    public const MODULE_CODE = 'staff-users';
    public const BATCH_NAME = 'Staff Migration Batch 1';

    private function __construct()
    {
    }
}
