<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

/**
 * The three permissions of the HR Employees API (plan HR-EMPLOYEES-PLAN, 2.1).
 * The seed is `seed-hr-employees-permissions.sql`; today one HR role holds all three.
 */
final class HrPermissions
{
    /** The directory: every route in the module needs it. */
    public const EMPLOYEES_READ = 'HR.EMPLOYEES.READ';

    /** The restricted block (birth date, home address, personal contact, leave balances). */
    public const EMPLOYEES_RESTRICTED_READ = 'HR.EMPLOYEES.RESTRICTED.READ';

    /**
     * Sync run status, and editing the department map (plan 4.1): both maintain how the
     * mirror is presented rather than reveal personal data, and no new permission is needed
     * (the HR role already holds it; reading the map needs only EMPLOYEES_READ).
     */
    public const EMPLOYEES_SYNC = 'HR.EMPLOYEES.SYNC';
}
