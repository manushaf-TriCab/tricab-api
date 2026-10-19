<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchRun;

/**
 * A run with its findings, recent ones first and then by confidence. `recent` holds the public ids of the
 * findings inside the 24-month window (see {@see ResearchFinding::withinRecentWindow()}).
 */
final readonly class ResearchRunDetail
{
    /**
     * @param list<ResearchFinding> $findings
     * @param list<string>          $recent
     */
    public function __construct(
        public ResearchRun $run,
        public array $findings,
        public array $recent,
    ) {
    }

    public function isRecent(ResearchFinding $finding): bool
    {
        return in_array($finding->publicId, $this->recent, true);
    }
}
