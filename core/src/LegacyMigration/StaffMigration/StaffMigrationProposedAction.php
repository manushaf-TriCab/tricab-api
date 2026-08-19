<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

final class StaffMigrationProposedAction
{
    public const CREATE_NEW_USER = 'CREATE_NEW_USER';
    public const SKIP_MISSING_EMAIL = 'SKIP_MISSING_EMAIL';
    public const SKIP_INVALID_EMAIL = 'SKIP_INVALID_EMAIL';
    public const SKIP_EXISTING_USER = 'SKIP_EXISTING_USER';
    public const BLOCK_DUPLICATE_EMAIL = 'BLOCK_DUPLICATE_EMAIL';
    public const BLOCK_ROLE_REVIEW = 'BLOCK_ROLE_REVIEW';
    public const BLOCK_STAFF_VERIFICATION = 'BLOCK_STAFF_VERIFICATION';
    public const BLOCK_OTHER_CONFLICT = 'BLOCK_OTHER_CONFLICT';

    private function __construct()
    {
    }
}
