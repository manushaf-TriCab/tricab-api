<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Legacy;

use DateTimeImmutable;
use TriCab\Api\Rfq\Legacy\LegacyRfqRowHash;
use TriCab\Api\Rfq\Legacy\LegacyRfqSource;

/** A stand-in for tc-web's MySQL store: rows held in memory, columns as the platform has them. */
final class InMemoryLegacyRfqSource implements LegacyRfqSource
{
    /**
     * @param array<string, list<string>> $columns
     * @param array<string, array<string, array<string, mixed>>> $rows table => key text => row
     */
    public function __construct(public array $columns, public array $rows = [])
    {
    }

    public function snapshotAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-20T06:00:00Z');
    }

    public function columns(string $table): array
    {
        return $this->columns[$table] ?? [];
    }

    public function rows(string $table, array $columns, array $pk): iterable
    {
        $rows = $this->rows[$table] ?? [];
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    public function rowsByKeys(string $table, array $columns, array $pk, array $keys): array
    {
        $wanted = array_flip(array_map(static fn (array $k): string => implode("\x1f", $k), $keys));

        return array_values(array_filter(
            $this->rows[$table] ?? [],
            static fn (array $row): bool => isset($wanted[LegacyRfqRowHash::key($row, $pk)]),
        ));
    }

    /**
     * @param list<string> $pk
     * @param array<string, mixed> $row
     */
    public function put(string $table, array $pk, array $row): void
    {
        $this->rows[$table][LegacyRfqRowHash::key($row, $pk)] = $row;
    }

    public function remove(string $table, string $key): void
    {
        unset($this->rows[$table][$key]);
    }
}
