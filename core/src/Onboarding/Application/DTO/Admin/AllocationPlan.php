<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

/**
 * What saving an allocation will do, worked out before anything is written.
 *
 * `kept` is the half that is easy to lose: boxes HR unticked that stay
 * allocated because something has been recorded against them. They are
 * returned to HR by name, because an untick that silently did nothing looks
 * like a bug, and one that silently withdrew started work would be one.
 */
final readonly class AllocationPlan
{
    /**
     * @param list<array{item: AllocatableItem, dueDate: ?string}> $allocate
     * @param list<CurrentAllocation> $withdraw
     * @param list<CurrentAllocation> $kept
     * @param list<array{allocation: CurrentAllocation, toDueDate: ?string}> $dueDateChanges
     */
    public function __construct(
        public array $allocate,
        public array $withdraw,
        public array $kept,
        public array $dueDateChanges,
    ) {
    }

    public function changesNothing(): bool
    {
        return $this->allocate === [] && $this->withdraw === [] && $this->dueDateChanges === [];
    }
}
