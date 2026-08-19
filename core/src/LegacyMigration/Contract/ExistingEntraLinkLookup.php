<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * SELECT-only lookup for existing Microsoft Entra links on identity.Users.
 */
interface ExistingEntraLinkLookup
{
    /**
     * @param list<string> $normalizedEmails
     * @return list<string> normalized emails with an active microsoft_entra ExternalIdentity
     */
    public function linkedNormalizedEmails(array $normalizedEmails): array;
}
