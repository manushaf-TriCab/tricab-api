<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

/**
 * One person as the allocation screen needs them: who they are, what they hold,
 * what could be given to them, and the token a save must present.
 */
final readonly class OnboardingPersonDetail
{
    /**
     * @param list<CurrentAllocation> $allocations
     * @param list<AllocatableItem> $catalogue
     */
    public function __construct(
        public OnboardingPersonSummary $person,
        public string $rowVersion,
        public array $allocations,
        public array $catalogue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'person' => $this->person->toArray(),
            'rowVersion' => $this->rowVersion,
            'allocations' => array_map(static fn (CurrentAllocation $a) => $a->toArray(), $this->allocations),
            'catalogue' => array_map(static fn (AllocatableItem $i) => $i->toArray(), $this->catalogue),
        ];
    }
}
