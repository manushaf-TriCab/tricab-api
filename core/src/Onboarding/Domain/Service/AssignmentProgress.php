<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * A 0–100 figure describing how far through an allocated pack someone is.
 *
 * Presentation only. Nobody is assessed on this and no decision may be taken
 * from it — a completion is evidenced by an assignment's status and its
 * immutable submission, never by a percentage. It exists because counting
 * completed items alone reports someone who has handed in eight of nine packs
 * as 0% on the ninth, which reads as "has not begun" to whoever is chasing them.
 *
 * Ported from `progressPercent` in the source's `api/_lib/status.ts`. Weights
 * live on {@see AssignmentStatus} so a status added later cannot be left out of
 * the calculation silently.
 */
final class AssignmentProgress
{
    /**
     * @param list<AssignmentStatus> $statuses
     */
    public static function percent(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }

        $earned = 0;
        foreach ($statuses as $status) {
            $earned += $status->progressWeightPercent();
        }

        return (int) round($earned / count($statuses));
    }
}
