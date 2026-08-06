<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

/**
 * Read-only WordPress connector abstraction.
 * Production connection is intentionally disabled in Phase 12; use FixtureLegacyProductSource in tests.
 */
final class LegacyWordPressProductSource implements LegacyProductSourceInterface
{
    /** @param list<LegacySourceRecord> $records */
    public function __construct(private readonly array $records = [])
    {
    }

    public function sourceSystemCode(): string
    {
        return 'wordpress';
    }

    public function extract(array $filter = []): \Generator
    {
        foreach ($this->records as $record) {
            if ($record->sourceSystem !== 'wordpress') {
                continue;
            }
            yield $record;
        }
    }
}
