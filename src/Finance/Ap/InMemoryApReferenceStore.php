<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApReferenceStore;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;

/**
 * The Settings lists held in memory: the default where no database is configured, and the tests'.
 * Ordered as the SQL orders them (case-insensitive text, then id), and unique on the spec's unique
 * column without regard to case, as the schema's collation is.
 */
final class InMemoryApReferenceStore implements ApReferenceStore
{
    /** @var array<string, ApReferenceRow> keyed by list and id */
    private array $rows = [];

    public function rows(ApReferenceSpec $spec, string $sortColumn, bool $descending, int $limit): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->list === $spec->list) {
                $out[] = $row;
            }
        }
        $key = static fn (ApReferenceRow $r): string => match ($sortColumn) {
            'created_date' => $r->createdAt->format('Y-m-d H:i:s.u'),
            'id' => $r->id,
            default => mb_strtolower($r->values[$sortColumn] ?? ''),
        };
        usort($out, static function (ApReferenceRow $a, ApReferenceRow $b) use ($key, $descending): int {
            $order = $key($a) <=> $key($b);

            return ($descending ? -$order : $order) ?: $a->id <=> $b->id;
        });

        return array_slice($out, 0, max(1, $limit));
    }

    public function find(ApReferenceSpec $spec, string $id): ?ApReferenceRow
    {
        return $this->rows[$spec->list . "\0" . $id] ?? null;
    }

    public function insert(ApReferenceRow $row): void
    {
        $this->assertUnique($row);
        $this->rows[$row->list . "\0" . $row->id] = $row;
    }

    public function update(ApReferenceRow $row): void
    {
        if (!isset($this->rows[$row->list . "\0" . $row->id])) {
            return;
        }
        $this->assertUnique($row);
        $this->rows[$row->list . "\0" . $row->id] = $row;
    }

    public function delete(ApReferenceSpec $spec, string $id): void
    {
        unset($this->rows[$spec->list . "\0" . $id]);
    }

    private function assertUnique(ApReferenceRow $row): void
    {
        $spec = ApReferenceSpec::for($row->list);
        $needle = mb_strtolower($row->values[$spec->unique] ?? '');
        foreach ($this->rows as $other) {
            if ($other->list === $row->list && $other->id !== $row->id && mb_strtolower($other->values[$spec->unique] ?? '') === $needle) {
                throw new ApConflict($spec->conflictMessage());
            }
        }
    }
}
