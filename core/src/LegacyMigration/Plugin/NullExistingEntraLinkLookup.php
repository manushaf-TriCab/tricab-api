<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\ExistingEntraLinkLookup;

final class NullExistingEntraLinkLookup implements ExistingEntraLinkLookup
{
    public function linkedNormalizedEmails(array $normalizedEmails): array
    {
        unset($normalizedEmails);

        return [];
    }
}
