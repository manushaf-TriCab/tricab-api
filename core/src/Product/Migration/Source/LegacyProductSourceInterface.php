<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

interface LegacyProductSourceInterface
{
    public function sourceSystemCode(): string;

    /**
     * @param array<string, mixed> $filter
     * @return \Generator<int, LegacySourceRecord>
     */
    public function extract(array $filter = []): \Generator;
}
