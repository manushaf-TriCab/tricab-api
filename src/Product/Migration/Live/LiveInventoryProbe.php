<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

interface LiveInventoryProbe
{
    /**
     * @return array{
     *   status: string,
     *   wordpress?: array<string, int|string|null>,
     *   tc?: array<string, int|string|null>,
     *   files?: array<string, int|string|null>,
     *   message?: string
     * }
     */
    public function probe(LiveLegacySourceConfig $config): array;
}
