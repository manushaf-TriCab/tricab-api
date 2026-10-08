<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

use TriCab\Core\Finance\Ar\Domain\ArOption;

/**
 * The Category and Method lists (`ar_option_lists`), and how many records carry each value.
 * Storage only: the rules (slugs, order, built-ins, archive versus delete) are `ArOptionService`'s.
 */
interface ArOptionStore
{
    /**
     * Runs `$work` as one unit: commits when it returns, rolls back when it throws, and
     * rethrows. A call inside another joins it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    /**
     * Every option of the list, archived ones too, by `sort_order` then label.
     *
     * @return list<ArOption>
     */
    public function options(string $listName): array;

    public function findOption(string $listName, string $value): ?ArOption;

    /**
     * Fills an EMPTY list with `$defaults`; a list that has rows is left alone. A duplicate-key race is not an error.
     *
     * @param list<ArOption> $defaults
     */
    public function seedIfEmpty(string $listName, array $defaults): void;

    public function insertOption(ArOption $option): void;

    /** Writes label, description, colour, active, order and the update time of an existing option. */
    public function updateOption(ArOption $option): void;

    public function deleteOption(string $listName, string $value): void;

    /** Adds one to the order of every option of the list at or above `$order`. */
    public function shiftOrders(string $listName, int $order): void;

    public function setOrder(string $listName, string $value, int $order): void;

    /**
     * How many records carry each value, binned ones included, over `follow_up_category` for the
     * category list and `proposed_response_type` for the method list.
     *
     * @return array<string, int> value => count
     */
    public function usage(string $listName): array;
}
