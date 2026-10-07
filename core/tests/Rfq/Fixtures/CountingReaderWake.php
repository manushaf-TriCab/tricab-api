<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use TriCab\Core\Rfq\Contract\RfqReaderWake;

/** Counts the wake-ups an upload sends the reader worker. */
final class CountingReaderWake implements RfqReaderWake
{
    public int $signals = 0;

    public function signal(): void
    {
        $this->signals++;
    }
}
