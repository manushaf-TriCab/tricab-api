<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

/**
 * Who one event writes to, snapshotted at event time. `missing` lists the
 * required roles that had no address (`role`, `type` to|cc): each becomes a
 * visible failed delivery, never a silent drop.
 */
final readonly class NprNotificationPlan
{
    /**
     * @param list<string>                                       $to
     * @param list<string>                                       $cc
     * @param list<array{role: string, type: string}>            $missing
     */
    public function __construct(
        public array $to,
        public array $cc,
        public array $missing,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->to === [] && $this->cc === [] && $this->missing === [];
    }
}
