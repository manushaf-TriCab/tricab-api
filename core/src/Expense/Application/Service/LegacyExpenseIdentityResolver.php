<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;

/**
 * Derives legacy ownership keys from ExpenseProfile only (server-side).
 * No display-name matching. No client-supplied identity.
 */
final class LegacyExpenseIdentityResolver
{
    /**
     * @return list<string> unique lowercased owner keys for created_by / submitted_by matching
     */
    public function ownerKeys(?ExpenseProfile $profile): array
    {
        if ($profile === null) {
            return [];
        }

        $keys = [];
        $login = strtolower(trim($profile->legacyLogin()));
        $email = strtolower(trim($profile->legacyEmail()));
        if ($login !== '') {
            $keys[$login] = true;
        }
        if ($email !== '') {
            $keys[$email] = true;
        }
        // Dirty legacy rows sometimes store login@tricab.com as created_by
        if ($login !== '' && !str_contains($login, '@')) {
            $keys[$login . '@tricab.com'] = true;
        }

        return array_keys($keys);
    }

    public function isResolved(?ExpenseProfile $profile): bool
    {
        return $this->ownerKeys($profile) !== [];
    }
}
