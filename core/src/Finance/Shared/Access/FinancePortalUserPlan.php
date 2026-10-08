<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

/**
 * What mapping an export would do. Nothing in it has been written.
 *
 *  - `toMap`: portal user, matched by email to exactly one platform user, not yet mapped
 *  - `alreadyMapped`: mapped before, to the same platform user (a re-run changes nothing)
 *  - `unmatched`: no platform user holds that email. The list Manusha resolves before a data migration
 *  - `conflicts`: left alone, with the reason: an email held by more than one platform user, a portal
 *    user mapped earlier to a different platform user, or a platform user two portal users would claim
 *  - `rejected`: export rows that could not be read
 */
final readonly class FinancePortalUserPlan
{
    /**
     * @param list<array{portalUserId: string, email: string, name: string, platformUserId: int}> $toMap
     * @param list<array{portalUserId: string, email: string, platformUserId: int}> $alreadyMapped
     * @param list<array{portalUserId: string, email: string, name: string, active: bool}> $unmatched
     * @param list<array{portalUserId: string, email: string, reason: string}> $conflicts
     * @param list<array{row: int, reason: string}> $rejected
     */
    public function __construct(
        public array $toMap,
        public array $alreadyMapped,
        public array $unmatched,
        public array $conflicts,
        public array $rejected,
    ) {
    }

    /** @return array{read: int, toMap: int, alreadyMapped: int, unmatched: int, conflicts: int, rejected: int} */
    public function counts(): array
    {
        $mapped = count($this->toMap);
        $already = count($this->alreadyMapped);
        $unmatched = count($this->unmatched);
        $conflicts = count($this->conflicts);

        return [
            'read' => $mapped + $already + $unmatched + $conflicts,
            'toMap' => $mapped,
            'alreadyMapped' => $already,
            'unmatched' => $unmatched,
            'conflicts' => $conflicts,
            'rejected' => count($this->rejected),
        ];
    }
}
