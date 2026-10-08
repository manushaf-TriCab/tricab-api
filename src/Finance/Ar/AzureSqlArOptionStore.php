<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Core\Finance\Ar\Contract\ArOptionStore;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArOption;

/**
 * The Category and Method lists in `finance.ar_option_lists` (`create-finance-ar-schema.sql`).
 * Every list sorts on bounded columns. A usage count reads `finance.ar_email_records`, binned
 * records included.
 */
final class AzureSqlArOptionStore extends AzureSqlArSupport implements ArOptionStore
{
    private const COLUMNS = '[list_name], [value], [label], [description], [color], [sort_order], [active], [is_system], [created_date], [updated_date]';

    /** The record column each list is stored in (`OPTION_COLUMNS`). */
    private const USAGE_COLUMN = [
        ArOption::LIST_CATEGORY => 'follow_up_category',
        ArOption::LIST_METHOD => 'proposed_response_type',
    ];

    public function options(string $listName): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[ar_option_lists] WHERE [list_name] = :list ORDER BY [sort_order], [label], [value]',
            ['list' => $listName],
        );

        return array_map(self::option(...), $rows);
    }

    public function findOption(string $listName, string $value): ?ArOption
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[ar_option_lists] WHERE [list_name] = :list AND [value] = :value',
            ['list' => $listName, 'value' => $value],
        );

        return $rows === [] ? null : self::option($rows[0]);
    }

    public function seedIfEmpty(string $listName, array $defaults): void
    {
        $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM [finance].[ar_option_lists] WHERE [list_name] = :list', ['list' => $listName]);
        if (self::int($rows[0]['n'] ?? 0) > 0) {
            return;
        }
        try {
            $this->transactional(function () use ($defaults): void {
                foreach ($defaults as $option) {
                    $this->insertOption($option);
                }
            });
        } catch (ArConflict) {
            // Another request seeded the list first; its rows stand and ours were rolled back.
        }
    }

    public function insertOption(ArOption $option): void
    {
        $this->write(
            'INSERT INTO [finance].[ar_option_lists] (' . self::COLUMNS . ')
             VALUES (:list, :value, :label, :description, :color, :sort, :active, :system, :created, :updated)',
            [
                'list' => $option->listName,
                'value' => $option->value,
                'label' => $option->label,
                'description' => $option->description,
                'color' => $option->color,
                'sort' => $option->sortOrder,
                'active' => $option->active ? 1 : 0,
                'system' => $option->isSystem ? 1 : 0,
                'created' => self::at($option->createdAt),
                'updated' => self::at($option->updatedAt),
            ],
            'That option already exists',
        );
    }

    public function updateOption(ArOption $option): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ar_option_lists]
                SET [label] = :label, [description] = :description, [color] = :color, [active] = :active, [updated_date] = :updated
              WHERE [list_name] = :list AND [value] = :value',
            [
                'label' => $option->label,
                'description' => $option->description,
                'color' => $option->color,
                'active' => $option->active ? 1 : 0,
                'updated' => self::at($option->updatedAt),
                'list' => $option->listName,
                'value' => $option->value,
            ],
        );
    }

    public function deleteOption(string $listName, string $value): void
    {
        $this->connection->execute('DELETE FROM [finance].[ar_option_lists] WHERE [list_name] = :list AND [value] = :value', ['list' => $listName, 'value' => $value]);
    }

    public function shiftOrders(string $listName, int $order): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ar_option_lists] SET [sort_order] = [sort_order] + 1 WHERE [list_name] = :list AND [sort_order] >= :order',
            ['list' => $listName, 'order' => $order],
        );
    }

    public function setOrder(string $listName, string $value, int $order): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ar_option_lists] SET [sort_order] = :order WHERE [list_name] = :list AND [value] = :value',
            ['order' => $order, 'list' => $listName, 'value' => $value],
        );
    }

    public function usage(string $listName): array
    {
        $column = self::USAGE_COLUMN[$listName] ?? null;
        if ($column === null) {
            return [];
        }
        $out = [];
        foreach ($this->connection->fetchAll('SELECT [' . $column . '] AS [v], COUNT(*) AS [n] FROM [finance].[ar_email_records] GROUP BY [' . $column . ']') as $row) {
            $out[self::text($row['v'])] = self::int($row['n']);
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function option(array $row): ArOption
    {
        return new ArOption(
            self::text($row['list_name']),
            self::text($row['value']),
            self::text($row['label']),
            self::text($row['description']),
            self::text($row['color']),
            self::int($row['sort_order']),
            self::bool($row['active']),
            self::bool($row['is_system']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }
}
