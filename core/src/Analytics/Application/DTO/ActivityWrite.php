<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\Analytics\Domain\ActivityPlatform;
use TriCab\Core\Analytics\Domain\ActivitySignalType;

/**
 * One accepted signal, already normalised, with the parts the service decided still need writing.
 * Every part is an idempotent upsert, so replaying a write never inflates a unique-user count.
 */
final readonly class ActivityWrite
{
    public function __construct(
        public int $userId,
        public bool $isStaff,
        public ActivityPlatform $platform,
        public string $sessionId,
        public string $moduleCode,
        public ActivitySignalType $type,
        public DateTimeImmutable $atUtc,
        public string $localDate,
        public DateTimeImmutable $hourStartUtc,
        public bool $writeSession,
        public bool $writeDaily,
        public bool $writeHourly,
        public bool $writeModuleDaily,
    ) {
    }

    public function sessionState(): string
    {
        return match ($this->type) {
            ActivitySignalType::Background => 'background',
            ActivitySignalType::End => 'ended',
            default => 'active',
        };
    }

    /** Views are the events behind the module activity share; heartbeats only keep a module's user present. */
    public function moduleViewIncrement(): int
    {
        return $this->type === ActivitySignalType::View ? 1 : 0;
    }

    public function writesAnything(): bool
    {
        return $this->writeSession || $this->writeDaily || $this->writeHourly || $this->writeModuleDaily;
    }
}
