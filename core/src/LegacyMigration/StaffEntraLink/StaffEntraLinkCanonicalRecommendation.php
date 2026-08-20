<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

final class StaffEntraLinkCanonicalRecommendation
{
    public const KEEP_EXISTING = 'KEEP_EXISTING';
    public const KEEP_MIGRATED = 'KEEP_MIGRATED';
    public const MERGE_REQUIRED = 'MERGE_REQUIRED';
    public const MANUAL_REVIEW = 'MANUAL_REVIEW';

    private function __construct()
    {
    }
}
