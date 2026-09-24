<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Contract\OnboardingRetentionRepository;

/**
 * The onboarding retention job (5.3). It deletes nothing, and says why.
 *
 * Every class in `onboarding.RetentionClasses` is seeded `RetentionDays = NULL`
 * — "no figure decided; keep" — and the owner set the question aside on
 * 23 Sep 2026 (skipped, not pending). So the job reads the table and reports
 * `KEEP` for each class. That is the whole of its behaviour today, and it is
 * built to say so rather than to look busy.
 *
 * **A figure appearing is a stop, not a go.** If somebody sets a period, the
 * job refuses rather than deletes: what a deletion must spare — the leaver's
 * 14-day window, Fair Work Act s.535's seven years for employee records,
 * immutable submissions, audit rows — has not been designed, and a purge
 * written in the moment a number shows up is how an induction a regulator asks
 * for goes missing. Building that routine is its own piece of work.
 */
final readonly class ReviewOnboardingRetentionService
{
    public function __construct(private OnboardingRetentionRepository $classes)
    {
    }

    /**
     * @return array{decision: 'keep_all'|'refused', classes: list<array{classCode: string, className: string, retentionDays: ?int, action: string}>, reason: string}
     */
    public function run(): array
    {
        $classes = [];
        $decided = [];
        foreach ($this->classes->listClasses() as $class) {
            $action = $class['retentionDays'] === null ? 'KEEP' : 'REFUSED_NO_ROUTINE';
            if ($class['retentionDays'] !== null) {
                $decided[] = $class['classCode'];
            }
            $classes[] = [
                'classCode' => $class['classCode'],
                'className' => $class['className'],
                'retentionDays' => $class['retentionDays'],
                'action' => $action,
            ];
        }

        if ($decided === []) {
            return [
                'decision' => 'keep_all',
                'classes' => $classes,
                'reason' => 'No retention period is set for any class, so every record is kept.',
            ];
        }

        return [
            'decision' => 'refused',
            'classes' => $classes,
            'reason' => sprintf(
                'A retention period is set for %s, but no deletion routine exists yet. Nothing was deleted.',
                implode(', ', $decided),
            ),
        ];
    }
}
