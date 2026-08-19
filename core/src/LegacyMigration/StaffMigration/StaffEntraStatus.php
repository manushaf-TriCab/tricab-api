<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

final class StaffEntraStatus
{
    public const NOT_LINKED = 'NOT_LINKED';
    public const LINK_REQUIRED = 'LINK_REQUIRED';
    public const LINKED = 'LINKED';
    public const CONFLICT = 'CONFLICT';

    private function __construct()
    {
    }
}
