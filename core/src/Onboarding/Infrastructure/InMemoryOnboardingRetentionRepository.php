<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Contract\OnboardingRetentionRepository;

/** Seeded as the schema seeds the table: six classes, no figure for any. */
final class InMemoryOnboardingRetentionRepository implements OnboardingRetentionRepository
{
    /** @var list<array{classCode: string, className: string, retentionDays: ?int, decidedAtUtc: ?string}> */
    public array $classes = [
        ['classCode' => 'C0', 'className' => 'Content', 'retentionDays' => null, 'decidedAtUtc' => null],
        ['classCode' => 'C1', 'className' => 'Workflow', 'retentionDays' => null, 'decidedAtUtc' => null],
        ['classCode' => 'C2', 'className' => 'Ordinary HR', 'retentionDays' => null, 'decidedAtUtc' => null],
        ['classCode' => 'C3', 'className' => 'Payroll-sensitive', 'retentionDays' => null, 'decidedAtUtc' => null],
        ['classCode' => 'C4', 'className' => 'Restricted HR', 'retentionDays' => null, 'decidedAtUtc' => null],
        ['classCode' => 'C5', 'className' => 'Generated documents', 'retentionDays' => null, 'decidedAtUtc' => null],
    ];

    public function listClasses(): array
    {
        return $this->classes;
    }
}
