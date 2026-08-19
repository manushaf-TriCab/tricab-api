<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;

final class NullExistingNormalizedEmailLookup implements ExistingNormalizedEmailLookup
{
    public function existingNormalizedEmails(array $normalizedEmails): array
    {
        unset($normalizedEmails);

        return [];
    }
}
