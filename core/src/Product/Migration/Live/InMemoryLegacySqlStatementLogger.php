<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

final class InMemoryLegacySqlStatementLogger implements LegacySqlStatementLogger
{
    /** @var list<LegacySqlQueryLogEntry> */
    private array $entries = [];

    public function log(LegacySqlQueryLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function entries(): array
    {
        return $this->entries;
    }
}
