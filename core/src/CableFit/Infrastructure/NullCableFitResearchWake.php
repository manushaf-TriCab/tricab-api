<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Infrastructure;

use TriCab\Core\CableFit\Contract\CableFitResearchWake;

/** A wake that tells nobody (and counts the calls, for tests). */
final class NullCableFitResearchWake implements CableFitResearchWake
{
    public int $signals = 0;

    public function signal(): void
    {
        $this->signals++;
    }
}
