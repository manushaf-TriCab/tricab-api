<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

final class LegacyTcProductSource implements LegacyProductSourceInterface
{
    /** @param list<LegacySourceRecord> $records */
    public function __construct(private readonly array $records = [])
    {
    }

    public function sourceSystemCode(): string
    {
        return 'tc';
    }

    public function extract(array $filter = []): \Generator
    {
        foreach ($this->records as $record) {
            if ($record->sourceSystem !== 'tc') {
                continue;
            }
            yield $record;
        }
    }
}
