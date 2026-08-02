<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use Throwable;

final class FakeSqlConnection implements SqlConnection
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var list<list<array<string, mixed>>> */
    private array $fetchQueue = [];

    /** @var list<array{sql: string, params: array<string, scalar|null>}> */
    public array $calls = [];

    public bool $beganTransaction = false;

    public bool $committed = false;

    public bool $rolledBack = false;

    private bool $inTransaction = false;

    public ?Throwable $failNextWrite = null;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function queueFetch(array $rows): void
    {
        $this->fetchQueue[] = $rows;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        $this->maybeFailWrite($sql);

        if ($this->fetchQueue !== []) {
            return array_shift($this->fetchQueue);
        }

        return $this->rows;
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        $this->maybeFailWrite($sql);
    }

    public function beginTransaction(): void
    {
        $this->beganTransaction = true;
        $this->inTransaction = true;
        $this->committed = false;
        $this->rolledBack = false;
    }

    public function commit(): void
    {
        $this->committed = true;
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        $this->rolledBack = true;
        $this->inTransaction = false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    private function maybeFailWrite(string $sql): void
    {
        $normalized = strtoupper(ltrim($sql));
        $isWrite = str_starts_with($normalized, 'INSERT')
            || str_starts_with($normalized, 'UPDATE')
            || str_starts_with($normalized, 'DELETE');

        if (!$isWrite || $this->failNextWrite === null) {
            return;
        }

        $error = $this->failNextWrite;
        $this->failNextWrite = null;

        throw $error;
    }
}
