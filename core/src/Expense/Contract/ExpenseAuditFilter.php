<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use DateTimeImmutable;

/**
 * Optional Activity filters (legacy te audit view: from, to, action, actor).
 * Dates are inclusive UTC calendar days.
 */
final class ExpenseAuditFilter
{
    public function __construct(
        public readonly ?DateTimeImmutable $fromUtc = null,
        public readonly ?DateTimeImmutable $toUtc = null,
        public readonly ?string $action = null,
        public readonly ?int $actorUserId = null,
    ) {
    }

    /** Inclusive start of the from day. */
    public function fromBoundary(): ?DateTimeImmutable
    {
        return $this->fromUtc?->setTime(0, 0, 0);
    }

    /** Exclusive end: start of the day after `to`. */
    public function toExclusiveBoundary(): ?DateTimeImmutable
    {
        return $this->toUtc?->setTime(0, 0, 0)->modify('+1 day');
    }

    /** @param array{actorUserId: int, action: string, receiptPublicId: ?string, detail: ?string, createdAtUtc?: string} $event */
    public function matches(array $event): bool
    {
        if ($this->action !== null && $event['action'] !== $this->action) {
            return false;
        }
        if ($this->actorUserId !== null && $event['actorUserId'] !== $this->actorUserId) {
            return false;
        }
        $at = isset($event['createdAtUtc']) && $event['createdAtUtc'] !== ''
            ? new DateTimeImmutable($event['createdAtUtc'])
            : null;
        if ($at !== null) {
            $from = $this->fromBoundary();
            $to = $this->toExclusiveBoundary();
            if ($from !== null && $at < $from) {
                return false;
            }
            if ($to !== null && $at >= $to) {
                return false;
            }
        }

        return true;
    }
}
