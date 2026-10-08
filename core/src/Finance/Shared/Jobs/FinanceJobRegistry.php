<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Jobs;

use InvalidArgumentException;

/**
 * The jobs the runner knows. Phase 1 registers none; each app's phase adds its own where
 * the container builds this (Tri-Task's daily summary is the first, in Phase 2).
 */
final class FinanceJobRegistry
{
    /** @var list<FinanceJob> */
    private array $jobs;

    /**
     * @param list<FinanceJob> $jobs
     */
    public function __construct(array $jobs = [])
    {
        $names = [];
        foreach ($jobs as $job) {
            $name = $job->name();
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a job name.', $name));
            }
            if (isset($names[$name])) {
                throw new InvalidArgumentException(sprintf('The job "%s" is registered twice.', $name));
            }
            $names[$name] = true;
        }
        usort($jobs, static fn (FinanceJob $a, FinanceJob $b): int => strcmp($a->name(), $b->name()));
        $this->jobs = $jobs;
    }

    /** @return list<FinanceJob> by name */
    public function all(): array
    {
        return $this->jobs;
    }
}
