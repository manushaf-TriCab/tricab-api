<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

final class LegacySqlQueryLogEntry
{
    public function __construct(
        public readonly string $timestampUtc,
        public readonly string $repository,
        public readonly string $method,
        public readonly string $sql,
        public readonly float $executionTimeMs,
        public readonly int $rowsReturned,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'timestampUtc' => $this->timestampUtc,
            'repository' => $this->repository,
            'method' => $this->method,
            'sql' => $this->sql,
            'executionTimeMs' => $this->executionTimeMs,
            'rowsReturned' => $this->rowsReturned,
        ];
    }
}
