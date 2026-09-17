<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use RuntimeException;
use TriCab\Core\QuoteOrder\Contract\LegacyQuoteWriteConnection;

/**
 * In-memory transactional connection for automated tests — never touches MySQL.
 */
final class InMemoryLegacyQuoteWriteConnection implements LegacyQuoteWriteConnection
{
    private bool $inTransaction = false;

    /** @var list<array{sql: string, params: array<string, scalar|null>}> */
    private array $committed = [];

    /** @var list<array{sql: string, params: array<string, scalar|null>}> */
    private array $pending = [];

    private int $nextHeaderId = 100000001;

    private int $nextLineId = 500001;

    private int $nextExtId = 900001;

    private ?string $lastInsertId = null;

    /** @var array<string, true> */
    private array $failOnSqlContaining = [];

    private bool $failOnCommit = false;

    public function failWhenSqlContains(string $needle): void
    {
        $this->failOnSqlContaining[$needle] = true;
    }

    public function failOnCommit(): void
    {
        $this->failOnCommit = true;
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new RuntimeException('Nested transactions are not supported.');
        }
        $this->inTransaction = true;
        $this->pending = [];
        $this->lastInsertId = null;
    }

    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new RuntimeException('No active transaction.');
        }
        if ($this->failOnCommit) {
            throw new RuntimeException('Simulated commit failure.');
        }
        foreach ($this->pending as $op) {
            $this->committed[] = $op;
        }
        $this->pending = [];
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        if (!$this->inTransaction) {
            return;
        }
        $this->pending = [];
        $this->inTransaction = false;
        $this->lastInsertId = null;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function execute(string $sql, array $params = []): int
    {
        if (!$this->inTransaction) {
            throw new RuntimeException('Writes must run inside a transaction.');
        }
        foreach (array_keys($this->failOnSqlContaining) as $needle) {
            if (str_contains($sql, $needle)) {
                throw new RuntimeException('Simulated SQL failure matching: ' . $needle);
            }
        }

        $normalized = strtoupper(preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql));
        if (str_starts_with($normalized, 'INSERT INTO') && str_contains($normalized, 'TQOTH')) {
            $id = (string) $this->nextHeaderId++;
            $this->lastInsertId = $id;
        } elseif (str_starts_with($normalized, 'INSERT INTO') && str_contains($normalized, 'TQOTI')) {
            $id = (string) $this->nextLineId++;
            $this->lastInsertId = $id;
        } elseif (str_starts_with($normalized, 'INSERT INTO') && str_contains($normalized, 'TQOTE')) {
            $id = (string) $this->nextExtId++;
            $this->lastInsertId = $id;
        } elseif (str_starts_with($normalized, 'UPDATE ')) {
            $this->lastInsertId = null;
        } else {
            throw new RuntimeException('Unsupported SQL in test harness: ' . $sql);
        }

        $this->pending[] = ['sql' => $sql, 'params' => $params];

        return 1;
    }

    public function query(string $sql, array $params = []): array
    {
        // In-memory harness does not implement SELECT; correlation probe uses InMemoryQuoteWriteCorrelationRepository.
        return [];
    }

    public function lastInsertId(): string
    {
        return $this->lastInsertId ?? '0';
    }

    /**
     * @return list<array{sql: string, params: array<string, scalar|null>}>
     */
    public function committedOperations(): array
    {
        return $this->committed;
    }

    /**
     * @return list<array{sql: string, params: array<string, scalar|null>}>
     */
    public function pendingOperations(): array
    {
        return $this->pending;
    }

    public function committedInsertCount(): int
    {
        $count = 0;
        foreach ($this->committed as $op) {
            if (str_starts_with(strtoupper(ltrim($op['sql'])), 'INSERT')) {
                ++$count;
            }
        }

        return $count;
    }
}
