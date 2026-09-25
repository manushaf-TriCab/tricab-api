<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

/**
 * Reads `onboarding.RetentionClasses`, the one place a retention period is
 * stated (5.3). Read-only: a figure is set by a person, deliberately, and the
 * table's own CHECK refuses one without who decided it and when.
 */
interface OnboardingRetentionRepository
{
    /**
     * @return list<array{classCode: string, className: string, retentionDays: ?int, decidedAtUtc: ?string}>
     */
    public function listClasses(): array;
}
