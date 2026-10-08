<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use Throwable;
use TriCab\Core\Finance\Ar\Contract\ArOptionStore;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArOption;

/**
 * The Category and Method lists held in memory, ordered as the SQL orders them (`sort_order`, then
 * label), with a transaction that restores a snapshot on failure. `InMemoryArStore` extends this
 * with the records, so a usage count sees them; until then no record carries anything.
 */
class InMemoryArOptionStore implements ArOptionStore
{
    /** @var array<string, ArOption> keyed by list and value */
    protected array $options = [];

    protected int $transactionDepth = 0;

    /**
     * @return array<string, int> value => records carrying it, for the list
     */
    protected function usageCounts(string $listName): array
    {
        return [];
    }

    public function transactional(callable $work): mixed
    {
        if ($this->transactionDepth > 0) {
            return $work();
        }
        $snapshot = unserialize(serialize(get_object_vars($this)));
        $this->transactionDepth = 1;
        try {
            return $work();
        } catch (Throwable $failure) {
            if (is_array($snapshot)) {
                foreach ($snapshot as $property => $value) {
                    if (is_string($property)) {
                        $this->{$property} = $value;
                    }
                }
            }
            throw $failure;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    private static function key(string $listName, string $value): string
    {
        return $listName . "\0" . strtolower($value);
    }

    public function options(string $listName): array
    {
        $out = [];
        foreach ($this->options as $option) {
            if ($option->listName === $listName) {
                $out[] = $option;
            }
        }
        usort($out, static fn (ArOption $a, ArOption $b): int => [$a->sortOrder, mb_strtolower($a->label), $a->value] <=> [$b->sortOrder, mb_strtolower($b->label), $b->value]);

        return $out;
    }

    public function findOption(string $listName, string $value): ?ArOption
    {
        return $this->options[self::key($listName, $value)] ?? null;
    }

    public function seedIfEmpty(string $listName, array $defaults): void
    {
        if ($this->options($listName) !== []) {
            return;
        }
        foreach ($defaults as $option) {
            $this->insertOption($option);
        }
    }

    public function insertOption(ArOption $option): void
    {
        $key = self::key($option->listName, $option->value);
        if (isset($this->options[$key])) {
            throw new ArConflict('That option already exists');
        }
        $this->options[$key] = $option;
    }

    public function updateOption(ArOption $option): void
    {
        $key = self::key($option->listName, $option->value);
        $current = $this->options[$key] ?? null;
        if ($current === null) {
            return;
        }
        // Only what the SQL writes: label, description, colour, active and the update time.
        $this->options[$key] = $current->with(
            label: $option->label,
            description: $option->description,
            color: $option->color,
            active: $option->active,
            updatedAt: $option->updatedAt,
        );
    }

    public function deleteOption(string $listName, string $value): void
    {
        unset($this->options[self::key($listName, $value)]);
    }

    public function shiftOrders(string $listName, int $order): void
    {
        foreach ($this->options as $key => $option) {
            if ($option->listName === $listName && $option->sortOrder >= $order) {
                $this->options[$key] = $option->with(sortOrder: $option->sortOrder + 1);
            }
        }
    }

    public function setOrder(string $listName, string $value, int $order): void
    {
        $key = self::key($listName, $value);
        if (isset($this->options[$key])) {
            $this->options[$key] = $this->options[$key]->with(sortOrder: $order);
        }
    }

    public function usage(string $listName): array
    {
        return $this->usageCounts($listName);
    }
}
