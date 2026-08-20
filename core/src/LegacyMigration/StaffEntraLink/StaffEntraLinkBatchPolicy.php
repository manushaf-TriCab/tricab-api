<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

final class StaffEntraLinkBatchPolicy
{
    public const BATCH_TYPE = 'STAFF_ENTRA_LINK_ACTIVATION';
    public const MODULE_CODE = 'staff-entra-link';
    public const BATCH_NAME = 'Staff Entra Linking + Activation';
    public const EXPECTED_MAPPING_COUNT = 617;

    private function __construct()
    {
    }
}
