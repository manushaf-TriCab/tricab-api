<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;

/**
 * Finds the platform user a piece of Tri-AR free text names, through `finance.portal_user_map` (F1.10). The source
 * kept a person as text: an address, a full name, or a portal user id. Only an exact portal user id or an address
 * the map recorded for exactly one portal user resolves; a name is never guessed at.
 */
final class ArMigrationUsers
{
    /** @var array<string, int> portal user id => platform user id */
    private array $byPortalId = [];

    /** @var array<string, list<int>> lower-case recorded address => platform user ids */
    private array $byEmail = [];

    /** @param array<array-key, FinancePortalUserMapping> $mappings the map's rows (its keys are portal user ids, which PHP may have turned into integers) */
    public function __construct(array $mappings = [])
    {
        foreach ($mappings as $mapping) {
            $this->byPortalId[$mapping->portalUserId] = $mapping->platformUserId;
            $email = strtolower(trim($mapping->recordedEmail));
            if ($email !== '') {
                $this->byEmail[$email][] = $mapping->platformUserId;
            }
        }
    }

    public function resolve(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (isset($this->byPortalId[$text])) {
            return $this->byPortalId[$text];
        }
        $ids = array_values(array_unique($this->byEmail[strtolower($text)] ?? []));

        return count($ids) === 1 ? $ids[0] : null;
    }
}
