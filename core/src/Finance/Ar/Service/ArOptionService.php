<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Contract\ArOptionStore;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArOption;

/**
 * The Category and Method lists and their Settings writes (`store.py` `_seed_options` to
 * `reorder_options`; routes 12 to 16), rule for rule, with the source's messages. The source's
 * 400 is a 422.
 *
 * Seeding is lazy: the first read or write of a list that has no rows fills it from
 * {@see ArOptionDefaults}; a list that has rows is never touched. The source seeded at start-up.
 */
final class ArOptionService
{
    public const MAX_DESCRIPTION = 2000;
    public const MAX_LABEL = 200;
    public const MAX_COLOR = 20;

    public function __construct(
        private readonly ArOptionStore $store,
        private readonly ArTime $time,
    ) {
    }

    /**
     * `GET /settings/options`: both lists, each option with how many records carry it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function lists(): array
    {
        $this->seed();
        $out = [];
        foreach (ArOption::LISTS as $name) {
            $usage = $this->store->usage($name);
            $out[$name] = array_map(
                static fn (ArOption $o): array => ArPresenter::option($o, $usage[$o->value] ?? 0),
                $this->store->options($name),
            );
        }

        return $out;
    }

    /**
     * The active options of a list in the Settings order: what the classifier is given as its criteria.
     *
     * @return list<ArOption>
     */
    public function activeOptions(string $listName): array
    {
        $this->checkList($listName);
        $this->seed();

        return array_values(array_filter($this->store->options($listName), static fn (ArOption $o): bool => $o->active));
    }

    /**
     * The values a person may newly assign: the active options of the list (`option_values`).
     *
     * @return list<string>
     */
    public function activeValues(string $listName): array
    {
        $this->checkList($listName);
        $this->seed();

        return array_values(array_map(
            static fn (ArOption $o): string => $o->value,
            array_filter($this->store->options($listName), static fn (ArOption $o): bool => $o->active),
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $listName, array $data): array
    {
        $this->checkList($listName);
        $label = ArRules::textOrEmpty($data['label'] ?? null);
        if ($label === '') {
            throw new ArInvalid('A name is required');
        }
        if (mb_strlen($label) > self::MAX_LABEL) {
            throw new ArInvalid('The name must be at most ' . self::MAX_LABEL . ' characters');
        }
        $base = self::slug($label);
        if ($base === '') {
            throw new ArInvalid('The name needs at least one letter or number');
        }
        $description = ArRules::textOrEmpty($data['description'] ?? null);
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new ArInvalid('The description must be at most ' . self::MAX_DESCRIPTION . ' characters');
        }
        $color = $this->color($data['color'] ?? null);
        $this->seed();

        return $this->store->transactional(function () use ($listName, $label, $base, $description, $color): array {
            $existing = $this->store->options($listName);
            $taken = array_map(static fn (ArOption $o): string => $o->value, $existing);
            foreach ($existing as $option) {
                if ($option->active && mb_strtolower($option->label) === mb_strtolower($label)) {
                    throw new ArInvalid(ArRules::repr($label) . ' is already in the list');
                }
            }
            $value = $base;
            $n = 2;
            while (in_array($value, $taken, true)) {
                $value = $base . '_' . $n;
                ++$n;
            }
            // New options go above "other", which stays last.
            $highest = -1;
            foreach ($existing as $option) {
                if ($option->value !== 'other') {
                    $highest = max($highest, $option->sortOrder);
                }
            }
            $order = $highest + 1;
            $this->store->shiftOrders($listName, $order);
            $now = $this->time->now();
            $created = new ArOption($listName, $value, $label, $description, $color, $order, true, false, $now, $now);
            $this->store->insertOption($created);

            return ArPresenter::option($created);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $listName, string $value, array $data): array
    {
        $this->checkList($listName);
        $this->seed();

        return $this->store->transactional(function () use ($listName, $value, $data): array {
            $current = $this->store->findOption($listName, $value);
            if ($current === null) {
                throw new ArInvalid('Option not found');
            }
            $changes = [];
            if (array_key_exists('label', $data)) {
                $label = ArRules::textOrEmpty($data['label']);
                if ($label === '') {
                    throw new ArInvalid('A name is required');
                }
                if (mb_strlen($label) > self::MAX_LABEL) {
                    throw new ArInvalid('The name must be at most ' . self::MAX_LABEL . ' characters');
                }
                $changes['label'] = $label;
            }
            if (array_key_exists('description', $data)) {
                $description = ArRules::textOrEmpty($data['description']);
                if (mb_strlen($description) > self::MAX_DESCRIPTION) {
                    throw new ArInvalid('The description must be at most ' . self::MAX_DESCRIPTION . ' characters');
                }
                $changes['description'] = $description;
            }
            if (array_key_exists('color', $data)) {
                $changes['color'] = $this->color($data['color']);
            }
            if (array_key_exists('active', $data)) {
                $active = ArRules::truthy($data['active']);
                if ($current->isSystem && !$active) {
                    throw new ArInvalid(ArRules::repr($current->label) . ' is built in and cannot be archived');
                }
                $changes['active'] = $active;
            }
            if ($changes === []) {
                return ArPresenter::option($current);
            }
            $updated = $current->with(...$changes, updatedAt: $this->time->now());
            $this->store->updateOption($updated);

            return ArPresenter::option($updated);
        });
    }

    /**
     * Delete an unused option; archive one that records still carry.
     *
     * @return array{deleted: bool, archived: bool, usage_count: int}
     */
    public function delete(string $listName, string $value): array
    {
        $this->checkList($listName);
        $this->seed();

        return $this->store->transactional(function () use ($listName, $value): array {
            $current = $this->store->findOption($listName, $value);
            if ($current === null) {
                throw new ArInvalid('Option not found');
            }
            if ($current->isSystem) {
                throw new ArInvalid(ArRules::repr($current->label) . ' is built in and cannot be removed');
            }
            $used = $this->store->usage($listName)[$value] ?? 0;
            if ($used > 0) {
                $this->store->updateOption($current->with(active: false, updatedAt: $this->time->now()));

                return ['deleted' => false, 'archived' => true, 'usage_count' => $used];
            }
            $this->store->deleteOption($listName, $value);

            return ['deleted' => true, 'archived' => false, 'usage_count' => 0];
        });
    }

    /**
     * @param mixed $values the new order: every option's value, once each
     * @return list<array<string, mixed>>
     */
    public function reorder(string $listName, mixed $values): array
    {
        $this->checkList($listName);
        $this->seed();

        return $this->store->transactional(function () use ($listName, $values): array {
            $existing = array_map(static fn (ArOption $o): string => $o->value, $this->store->options($listName));
            $given = is_array($values) ? array_values($values) : [];
            $strings = array_values(array_filter($given, 'is_string'));
            $sortedGiven = $strings;
            $sortedExisting = $existing;
            sort($sortedGiven);
            sort($sortedExisting);
            if (count($strings) !== count($given) || $sortedGiven !== $sortedExisting) {
                throw new ArInvalid('The new order must list every option exactly once');
            }
            foreach ($strings as $order => $value) {
                $this->store->setOrder($listName, $value, $order);
            }

            return array_map(static fn (ArOption $o): array => ArPresenter::option($o), $this->store->options($listName));
        });
    }

    private function checkList(string $listName): void
    {
        if (!in_array($listName, ArOption::LISTS, true)) {
            throw new ArInvalid('Unknown list: ' . ArRules::repr($listName));
        }
    }

    /** Fills an empty list from the defaults (the source's `_seed_options`); a list with rows is left alone. */
    private function seed(): void
    {
        foreach (ArOption::LISTS as $name) {
            $this->store->seedIfEmpty($name, ArOptionDefaults::options($name, $this->time->now()));
        }
    }

    /** `data.get("color") or "slate"`. */
    private function color(mixed $value): string
    {
        $color = ArRules::truthy($value) && is_scalar($value) ? (string) $value : 'slate';
        if (mb_strlen($color) > self::MAX_COLOR) {
            throw new ArInvalid('The colour must be at most ' . self::MAX_COLOR . ' characters');
        }

        return $color;
    }

    /** `_slug`: lower-case, runs of anything outside a-z0-9 become `_`, trimmed of `_`, cut to 48. */
    public static function slug(string $label): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)) ?? '';

        return substr(trim($slug, '_'), 0, 48);
    }
}
