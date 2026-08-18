<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

/**
 * Read-only connection to a legacy production database.
 * Implementations MUST refuse non-SELECT/SHOW/DESCRIBE/EXPLAIN SQL.
 */
interface LegacyReadOnlyConnection
{
    /**
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function query(
        string $sql,
        array $params = [],
        string $repository = 'unknown',
        string $method = 'unknown',
    ): array;

    public function sourceLabel(): string;
}
