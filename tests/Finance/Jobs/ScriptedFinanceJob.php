<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Jobs;

use Closure;
use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/** A job whose work is a closure, counting how often it ran. */
final class ScriptedFinanceJob implements FinanceJob
{
    public int $runs = 0;

    /**
     * @param Closure(): array<string, scalar|null> $work
     */
    public function __construct(
        private readonly string $name,
        private readonly FinanceJobSchedule $schedule,
        private readonly Closure $work,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function schedule(): FinanceJobSchedule
    {
        return $this->schedule;
    }

    public function run(DateTimeImmutable $now): array
    {
        ++$this->runs;

        return ($this->work)();
    }
}
