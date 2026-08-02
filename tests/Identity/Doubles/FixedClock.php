<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Shared\Time\Clock;

final class FixedClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {
    }

    public static function utc(string $datetime): self
    {
        return new self(new DateTimeImmutable($datetime, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
