<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use RuntimeException;
use TriCab\Core\Finance\Shared\Odoo\OdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * A stand-in for Odoo: rows held per model, no network and no key. It honours the one
 * condition the sync's incremental pass depends on, `write_date >= <time>` (a string
 * comparison, as Odoo's `YYYY-MM-DD HH:MM:SS` sorts), and ignores the rest of the domain
 * (the stub's rows are already what the domain would select). Every call is recorded.
 */
final class StubOdooClient implements OdooClient
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    /** @var list<array{model: string, domain: list<array{0: string, 1: string, 2: mixed}>, fields: list<string>}> */
    public array $calls = [];

    /** @var array<string, OdooError|RuntimeException> a model whose read fails */
    public array $failures = [];

    public function searchRead(string $model, array $domain, array $fields): array
    {
        $this->calls[] = ['model' => $model, 'domain' => $domain, 'fields' => $fields];
        if (isset($this->failures[$model])) {
            throw $this->failures[$model];
        }

        $since = null;
        foreach ($domain as $condition) {
            if ($condition[0] === 'write_date' && $condition[1] === '>=') {
                $since = is_string($condition[2]) ? $condition[2] : null;
            }
        }

        $rows = [];
        foreach ($this->rows[$model] ?? [] as $row) {
            $written = $row['write_date'] ?? '';
            if ($since !== null && (!is_string($written) || $written < $since)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function put(string $model, array $row): void
    {
        foreach ($this->rows[$model] ?? [] as $index => $existing) {
            if ($existing['id'] === $row['id']) {
                $this->rows[$model][$index] = $row;

                return;
            }
        }
        $this->rows[$model][] = $row;
    }

    public function remove(string $model, int $id): void
    {
        $this->rows[$model] = array_values(array_filter($this->rows[$model] ?? [], static fn (array $row): bool => $row['id'] !== $id));
    }

    /**
     * The calls made for one model, newest last.
     *
     * @return list<array{model: string, domain: list<array{0: string, 1: string, 2: mixed}>, fields: list<string>}>
     */
    public function callsFor(string $model): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['model'] === $model));
    }
}
