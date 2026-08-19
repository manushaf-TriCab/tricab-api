<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\ExistingStaffUserDetailLookup;

final class NullExistingStaffUserDetailLookup implements ExistingStaffUserDetailLookup
{
    public function byNormalizedEmails(array $normalizedEmails): array
    {
        unset($normalizedEmails);

        return [];
    }
}
