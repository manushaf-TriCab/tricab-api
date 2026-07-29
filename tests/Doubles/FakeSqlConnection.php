<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use TriCab\Api\Infrastructure\Database\SqlConnection;

final class FakeSqlConnection implements SqlConnection
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var list<array{sql: string, params: array<string, scalar|null>}> */
    public array $calls = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        return $this->rows;
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
    }
}
