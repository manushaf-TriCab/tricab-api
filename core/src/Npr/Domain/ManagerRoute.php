<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

use DateTimeImmutable;

/**
 * One row of `[npr].[ManagerRoutes]`: who endorses a branch's requests, and when.
 * A delegate route (`isDelegate`) is an effective-dated override and wins over
 * the branch's regular route while it is active. Dates are whole days, both
 * ends inclusive; no end means open-ended.
 */
final readonly class ManagerRoute
{
    public function __construct(
        public int $id,
        public string $branch,
        public NprPerson $manager,
        public bool $isDelegate,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public ?int $approvedByUserId = null,
    ) {
    }

    public function isActiveOn(DateTimeImmutable $day): bool
    {
        $day = $day->setTime(0, 0);

        return $this->validFrom <= $day && ($this->validTo === null || $day <= $this->validTo);
    }

    /** Same branch and same kind (a delegate may sit over the regular route) with windows that share a day. */
    public function overlaps(string $branch, bool $isDelegate, DateTimeImmutable $from, ?DateTimeImmutable $to): bool
    {
        if (strcasecmp($this->branch, $branch) !== 0 || $this->isDelegate !== $isDelegate) {
            return false;
        }

        return $this->validFrom <= ($to ?? $this->validFrom->setDate(9999, 12, 31))
            && $from <= ($this->validTo ?? $from->setDate(9999, 12, 31));
    }
}
