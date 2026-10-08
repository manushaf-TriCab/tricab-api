<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

/**
 * What a run found and did, as counts and source row ids only. It has no way to take a subject, a name, an
 * address, a body, a file name or a message id: a problem is a table, a source row id (shown only when it looks
 * like an id) and a fixed code word.
 */
final class ArMigrationReport
{
    /** @var array<string, array<string, int>> */
    private array $sections = [];

    /** @var list<array{table: string, id: string, kind: string}> */
    private array $problems = [];

    /** @var list<array{table: string, id: string}> */
    private array $conflicts = [];

    /** @var list<string> */
    private array $blockers = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    public function add(string $section, string $key, int $by = 1): void
    {
        $this->sections[$section][$key] = ($this->sections[$section][$key] ?? 0) + $by;
    }

    public function count(string $section, string $key): int
    {
        return $this->sections[$section][$key] ?? 0;
    }

    /** A row that was not (or could not be) written, and why. */
    public function problem(string $table, string $sourceId, string $kind): void
    {
        $this->problems[] = ['table' => $table, 'id' => self::printable($sourceId), 'kind' => $kind];
        $this->add('problems', $table . '.' . $kind);
    }

    /** A target row the tool did not migrate that holds what this source row would. */
    public function conflict(string $table, string $sourceId): void
    {
        $this->conflicts[] = ['table' => $table, 'id' => self::printable($sourceId)];
        $this->add('conflicts', $table);
    }

    /** Something that makes `--apply` refuse. */
    public function blocker(string $reason): void
    {
        $this->blockers[] = $reason;
    }

    /** A plain value (a flag, a timestamp) to show beside the counts. */
    public function set(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    public function problemCount(): int
    {
        return count($this->problems);
    }

    public function conflictCount(): int
    {
        return count($this->conflicts);
    }

    /** @return list<array{table: string, id: string, kind: string}> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<array{table: string, id: string}> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sections = $this->sections;
        foreach ($sections as &$keys) {
            ksort($keys);
        }
        unset($keys);
        ksort($sections);

        return $sections + $this->extra + ['blockers' => count($this->blockers)];
    }

    /** An id is shown only if it is made of id characters; anything else could be content. */
    private static function printable(string $id): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : '(not shown)';
    }
}
