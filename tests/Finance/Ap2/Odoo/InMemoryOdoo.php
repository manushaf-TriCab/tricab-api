<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use TriCab\Core\Finance\Ap2\Contract\Ap2OdooRecordWriter;
use TriCab\Core\Finance\Shared\Odoo\OdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * A small Odoo for the gateway tests: tables of rows, `search_read` that applies the domain (`=`, `!=`, `in`, `<=`,
 * `>=`, and `field.name` through a many2one's display name) and returns only the asked fields, and `create` that
 * appends a row. A model in `$refused` fails as Odoo does for a user without the rights.
 */
final class InMemoryOdoo implements OdooClient, Ap2OdooRecordWriter
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];

    /** @var list<string> */
    public array $refused = [];

    /** @var list<array{model: string, domain: list<array{0: string, 1: string, 2: mixed}>, fields: list<string>}> */
    public array $reads = [];

    /** @var list<array{model: string, values: array<string, mixed>}> */
    public array $creates = [];

    private int $nextId = 5000;

    /** @param array<string, mixed> $row */
    public function add(string $model, array $row): void
    {
        $this->tables[$model][] = $row;
    }

    public function searchRead(string $model, array $domain, array $fields): array
    {
        $this->reads[] = ['model' => $model, 'domain' => $domain, 'fields' => $fields];
        if (in_array($model, $this->refused, true)) {
            throw new OdooError(sprintf('Odoo %s.search_read returned HTTP 403: You are not allowed to access \'%s\' records.', $model, $model));
        }
        $out = [];
        foreach ($this->tables[$model] ?? [] as $row) {
            foreach ($domain as [$field, $op, $value]) {
                if (!self::matches($row, $field, $op, $value)) {
                    continue 2;
                }
            }
            $out[] = array_intersect_key($row, array_flip($fields)) + ['id' => $row['id'] ?? null];
        }

        return $out;
    }

    public function create(string $model, array $values): int
    {
        if (in_array($model . '.create', $this->refused, true)) {
            throw new OdooError(sprintf('Odoo %s.create returned HTTP 403: access denied', $model));
        }
        $id = ++$this->nextId;
        $this->creates[] = ['model' => $model, 'values' => $values];
        $this->tables[$model][] = ['id' => $id, 'name' => $model === 'account.move' ? '/' : ($values['name'] ?? '')] + $values;

        return $id;
    }

    /** @param array<string, mixed> $row */
    private static function matches(array $row, string $field, string $op, mixed $value): bool
    {
        if (str_contains($field, '.')) {
            [$field] = explode('.', $field, 2);
            $actual = is_array($row[$field] ?? null) ? ($row[$field][1] ?? false) : false;
        } else {
            $actual = $row[$field] ?? false;
            // A many2one compares by its id.
            if (is_array($actual) && array_is_list($actual) && count($actual) === 2 && is_int($actual[0]) && is_string($actual[1])) {
                $actual = $actual[0];
            }
        }

        return match ($op) {
            '=' => $actual === $value,
            '!=' => $actual !== $value,
            'in' => is_array($value) && in_array($actual, $value, true),
            '<=' => is_string($actual) && is_string($value) && $actual <= $value,
            '>=' => is_string($actual) && is_string($value) && $actual >= $value,
            default => throw new \LogicException('Unsupported operator ' . $op),
        };
    }
}
