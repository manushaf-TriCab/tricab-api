<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationPlan;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationSelection;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\AllocationPolicy;

/**
 * Turns "these are the boxes HR ticked" into allocations, withdrawals and due
 * date changes.
 *
 * The selection is the whole list, as the source's three PUT endpoints take it:
 * what is ticked is allocated, what is not is taken away where it may be. That
 * shape is what makes a save idempotent — sending the same list twice changes
 * nothing the second time.
 *
 * ## The rules, and where each comes from
 *
 *  - **Explicit only** (invariant 8). Nothing is added that was not ticked.
 *  - **Only untouched work is withdrawn** (invariant 9). An unticked assignment
 *    with any evidence, or that anybody has moved on from `not_started`, stays
 *    allocated and is reported back as kept. The source deletes untouched rows;
 *    here withdrawal is a date, so even an untouched row's history survives.
 *  - **Allocation is by item, not version.** A person holding version 1 of a
 *    policy has it allocated even after version 2 is published; carrying them
 *    forward is supersession's job, not the ticklist's. So "ticked and already
 *    held" matches on the item, whatever version they hold.
 *  - **A leaver gets nothing new** ({@see AllocationPolicy}). Unticking is still
 *    allowed for them: taking work away from somebody who has gone produces no
 *    evidence dated after their last day.
 *  - **A due date moves only on unfinished work.** Changing the deadline of a
 *    completed item would rewrite the record of whether it was on time.
 */
final class AllocationPlanner
{
    /**
     * @param list<AllocationSelection> $selection
     * @param list<AllocatableItem> $catalogue
     * @param list<CurrentAllocation> $current
     */
    public static function plan(
        array $selection,
        array $catalogue,
        array $current,
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): AllocationPlan {
        $wanted = [];
        foreach ($selection as $choice) {
            // A repeated box is one box. The last due date given for it wins,
            // which is what a form that sent it twice would have meant.
            $wanted[$choice->key()] = $choice;
        }

        $available = [];
        foreach ($catalogue as $item) {
            $available[$item->key()] = $item;
        }

        $held = [];
        foreach ($current as $allocation) {
            $held[$allocation->key()][] = $allocation;
        }

        $allocate = [];
        $dueDateChanges = [];

        foreach ($wanted as $key => $choice) {
            if (isset($held[$key])) {
                foreach ($held[$key] as $allocation) {
                    if ($allocation->dueDate !== $choice->dueDate && !$allocation->status->isFinal()) {
                        $dueDateChanges[] = ['allocation' => $allocation, 'toDueDate' => $choice->dueDate];
                    }
                }
                continue;
            }

            $item = $available[$key] ?? null;
            if ($item === null) {
                throw AllocationNotPermitted::unavailable($choice->itemType, $choice->itemPublicId);
            }

            AllocationPolicy::assertPersonAllocatable($departedAtUtc, $nowUtc);
            $allocate[] = ['item' => $item, 'dueDate' => $choice->dueDate];
        }

        $withdraw = [];
        $kept = [];
        foreach ($current as $allocation) {
            if (isset($wanted[$allocation->key()])) {
                continue;
            }
            if ($allocation->isUntouched()) {
                $withdraw[] = $allocation;
            } else {
                $kept[] = $allocation;
            }
        }

        return new AllocationPlan($allocate, $withdraw, $kept, $dueDateChanges);
    }
}
