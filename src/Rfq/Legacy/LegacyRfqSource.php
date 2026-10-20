<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use DateTimeImmutable;

/** The old app's RFQ store, read at one instant. */
interface LegacyRfqSource
{
    /** When the consistent snapshot was taken. */
    public function snapshotAt(): DateTimeImmutable;

    /** @return list<string> the table's columns in order; empty when the table is missing */
    public function columns(string $table): array;

    /**
     * Every row, ordered by primary key, read in pages so a table never has
     * to fit in memory.
     *
     * @param list<string> $columns
     * @param list<string> $pk
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(string $table, array $columns, array $pk): iterable;

    /**
     * The rows with these primary keys (each key a list of values in `$pk`
     * order). Missing keys are simply absent.
     *
     * @param list<string> $columns
     * @param list<string> $pk
     * @param list<list<string>> $keys
     *
     * @return list<array<string, mixed>>
     */
    public function rowsByKeys(string $table, array $columns, array $pk, array $keys): array;
}
