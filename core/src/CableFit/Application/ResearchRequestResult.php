<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

/**
 * What Evaluate's research request did: queued a new run, or answered an existing succeeded one (`reused`),
 * which costs nothing and does not count towards the daily cap.
 */
final readonly class ResearchRequestResult
{
    public function __construct(
        public string $runPublicId,
        public string $status,
        public bool $reused,
    ) {
    }
}
