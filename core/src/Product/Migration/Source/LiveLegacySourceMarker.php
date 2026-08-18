<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

/**
 * Marker for sources that read live legacy production data.
 * Execute/import to Product domain remains blocked for these sources.
 */
interface LiveLegacySourceMarker
{
    public function isLiveReadOnly(): bool;
}
