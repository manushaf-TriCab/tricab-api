<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

interface LegacySqlStatementLogger
{
    public function log(LegacySqlQueryLogEntry $entry): void;

    /** @return list<LegacySqlQueryLogEntry> */
    public function entries(): array;
}
