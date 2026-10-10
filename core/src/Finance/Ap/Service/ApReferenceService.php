<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Contract\ApReferenceStore;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;

/**
 * The three Settings lists: companies, cost centres and account categories (routes 13 to 24;
 * `companies_*`, `cost_centres_*` and `account_categories_*` in `app.py`, `create_row`, `update_row`
 * and `delete_row` in `store.py`), with the source's keys and messages. The source's 400 is a 422.
 *
 * Kept as the source had them:
 * - a create needs the list's one required field (`name` for a company, `code` for the other two),
 *   stripped, and says "name is required" / "code is required" when it is empty;
 * - only the list's own columns are read from a body, and `is_active` is by truthiness;
 * - a PATCH changes only the keys it carries, and an unknown id is "Not found";
 * - a DELETE of an id that is not there still answers `{ok: true}`.
 *
 * What the platform adds, because the columns have widths and a unique key: a value too long for its
 * column is a 422 (the source's SQLite stored anything), a duplicate of the unique column is a 409
 * (the source answered 500), and a PATCH may not blank the required field (the source let it).
 * Long text (`sites`, `former_names`) is not sortable (question Q5).
 */
final class ApReferenceService
{
    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT = 1000;

    public function __construct(
        private readonly ApReferenceStore $store,
        private readonly ApTime $time,
    ) {
    }

    /**
     * `GET /<list>`: `list_rows(table, sort, limit)`. `sort` is a column with `-` for descending; a column
     * the list cannot sort on falls back to `created_date`, as the source's unknown ones did.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $list, mixed $sort = null, mixed $limit = null): array
    {
        $spec = ApReferenceSpec::for($list);
        $text = is_string($sort) && $sort !== '' ? $sort : '-created_date';
        $column = ltrim($text, '-');
        if (!in_array($column, $spec->sortable(), true)) {
            $column = 'created_date';
        }
        $rows = $this->store->rows($spec, $column, str_starts_with($text, '-'), self::limit($limit));

        return array_map(static fn (ApReferenceRow $row): array => ApPresenter::reference($row), $rows);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $list, array $data): array
    {
        $spec = ApReferenceSpec::for($list);
        $values = [];
        foreach ($spec->fields as $field => $width) {
            $values[$field] = self::text($spec, $field, $data[$field] ?? null);
        }
        if ($values[$spec->required] === '') {
            throw new ApInvalid($spec->required . ' is required');
        }
        self::checkWidths($spec, $values);
        $active = array_key_exists('is_active', $data) ? ApRules::truthy($data['is_active']) : true;
        $row = new ApReferenceRow($list, ApRules::newId(), $values, $active, $this->time->now());
        $this->store->insert($row);

        return ApPresenter::reference($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $list, string $id, array $data): array
    {
        $spec = ApReferenceSpec::for($list);
        $current = $this->store->find($spec, $id);
        if ($current === null) {
            throw new ApNotFound('Not found');
        }
        $values = [];
        foreach ($spec->fields as $field => $width) {
            if (array_key_exists($field, $data)) {
                $values[$field] = self::text($spec, $field, $data[$field]);
            }
        }
        if (array_key_exists($spec->required, $values) && $values[$spec->required] === '') {
            throw new ApInvalid($spec->required . ' is required');
        }
        self::checkWidths($spec, $values);
        $active = array_key_exists('is_active', $data) ? ApRules::truthy($data['is_active']) : null;
        if ($values === [] && $active === null) {
            return ApPresenter::reference($current);
        }
        $updated = $current->with($values, $active);
        $this->store->update($updated);

        return ApPresenter::reference($updated);
    }

    /** @return array{ok: bool} */
    public function delete(string $list, string $id): array
    {
        $this->store->delete(ApReferenceSpec::for($list), $id);

        return ['ok' => true];
    }

    private static function limit(mixed $limit): int
    {
        if (!is_numeric($limit)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min((int) $limit, self::MAX_LIMIT));
    }

    /** A column's value: stripped, except the long text (`sites`, `former_names`), which the source stored as it came. */
    private static function text(ApReferenceSpec $spec, string $field, mixed $value): string
    {
        if (in_array($field, $spec->unsortable, true)) {
            return is_scalar($value) ? (string) $value : '';
        }

        return ApRules::textOrEmpty($value);
    }

    /** @param array<string, string> $values */
    private static function checkWidths(ApReferenceSpec $spec, array $values): void
    {
        foreach ($values as $field => $value) {
            $width = $spec->fields[$field];
            if (mb_strlen($value) > $width) {
                throw new ApInvalid('The ' . str_replace('_', ' ', $field) . ' must be at most ' . $width . ' characters');
            }
        }
    }
}
