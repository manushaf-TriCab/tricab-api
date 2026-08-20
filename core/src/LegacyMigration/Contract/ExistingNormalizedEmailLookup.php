<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * SELECT-only lookup against identity.Users.NormalizedEmailAddress.
 * Implementations must never insert or update identity tables.
 */
interface ExistingNormalizedEmailLookup
{
    /**
     * @param list<string> $normalizedEmails
     * @return list<string> emails that already exist on the new platform
     */
    public function existingNormalizedEmails(array $normalizedEmails): array;
}
