<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Read-only tc repository. SELECT/SHOW/DESCRIBE only via guarded connection.
 */
final class MysqlLegacyTcRepository implements LegacyTcRepository
{
    private const REPO = 'MysqlLegacyTcRepository';

    private const PRODUCT_TABLES = [
        'tpro',
        'tpro_ext',
        'cable_specs',
        'accessory_specs',
        'descript',
        'spec_labels',
    ];

    public function __construct(private readonly LegacyReadOnlyConnection $connection)
    {
    }

    public function findVariants(array $filter = []): array
    {
        if (!$this->tableExists('tpro')) {
            return [];
        }
        $limit = max(1, min(500, (int) ($filter['maxRecords'] ?? 50)));
        $style = isset($filter['styleCode']) ? strtoupper(MigrationPayload::string($filter['styleCode'])) : null;
        $cols = $this->existingColumns('tpro', ['ida', 'style', 'product_code', 'sku', 'cores', 'conductor_size', 'od', 'voltage', 'nc', 'xa']);
        $select = $cols === [] ? '*' : implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
        $sql = "SELECT {$select} FROM `tpro` WHERE 1 = 1";
        $params = [];
        if ($style !== null && $style !== '') {
            if (in_array('style', $cols, true)) {
                $sql .= ' AND UPPER(`style`) = :styleCode';
            } else {
                $sql .= ' AND UPPER(SUBSTRING(`ida`, 1, 3)) = :styleCode';
            }
            $params['styleCode'] = $style;
        }
        $sql .= ' ORDER BY `ida` ASC LIMIT ' . $limit;

        return $this->connection->query($sql, $params, self::REPO, 'findVariants');
    }

    public function findSpecifications(array $filter = []): array
    {
        $out = [];
        $limit = max(1, min(500, (int) ($filter['maxRecords'] ?? 50)));
        $style = isset($filter['styleCode']) ? strtoupper(MigrationPayload::string($filter['styleCode'])) : null;

        if ($this->tableExists('cable_specs')) {
            $cols = $this->existingColumns('cable_specs', ['cable_style', 'od', 'nc', 'xa', 'ct', 'volts', 'weight']);
            $select = $cols === [] ? '*' : implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
            $sql = "SELECT {$select} FROM `cable_specs` WHERE 1 = 1";
            $params = [];
            if ($style !== null && $style !== '' && in_array('cable_style', $cols, true)) {
                $sql .= ' AND UPPER(`cable_style`) = :styleCode';
                $params['styleCode'] = $style;
            }
            $sql .= ' LIMIT ' . $limit;
            foreach ($this->connection->query($sql, $params, self::REPO, 'findSpecifications.cable') as $row) {
                $styleCode = MigrationPayload::string($row['cable_style'] ?? $style ?? '');
                foreach ($row as $field => $value) {
                    if ($field === 'cable_style' || $value === null || $value === '') {
                        continue;
                    }
                    $out[] = [
                        '_table' => 'cable_specs',
                        'style_code' => $styleCode,
                        'cable_style' => $styleCode,
                        'field' => (string) $field,
                        'value' => $value,
                        'parent' => $styleCode,
                    ];
                }
            }
        }

        return $out;
    }

    public function findCodes(array $filter = []): array
    {
        $variants = $this->findVariants($filter);
        $out = [];
        foreach ($variants as $row) {
            $ida = MigrationPayload::string($row['ida'] ?? $row['product_code'] ?? '');
            if ($ida === '') {
                continue;
            }
            $out[] = [
                'ida' => $ida,
                'product_code' => $ida,
                'sku' => MigrationPayload::string($row['sku'] ?? $ida),
                'style_code' => strtoupper(MigrationPayload::string($row['style'] ?? substr($ida, 0, 3))),
            ];
        }

        return $out;
    }

    public function findAccessories(array $filter = []): array
    {
        if (!$this->tableExists('accessory_specs')) {
            return [];
        }
        $limit = max(1, min(500, (int) ($filter['maxRecords'] ?? 50)));
        $style = isset($filter['styleCode']) ? strtoupper(MigrationPayload::string($filter['styleCode'])) : null;
        $cols = $this->existingColumns('accessory_specs', ['product_code', 'style', 'style_code', 'description']);
        $select = $cols === [] ? '*' : implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
        $sql = "SELECT {$select} FROM `accessory_specs` WHERE 1 = 1";
        $params = [];
        if ($style !== null && $style !== '') {
            if (in_array('style', $cols, true)) {
                $sql .= ' AND UPPER(`style`) = :styleCode';
                $params['styleCode'] = $style;
            } elseif (in_array('style_code', $cols, true)) {
                $sql .= ' AND UPPER(`style_code`) = :styleCode';
                $params['styleCode'] = $style;
            } elseif (in_array('product_code', $cols, true)) {
                $sql .= ' AND UPPER(SUBSTRING(`product_code`, 1, 3)) = :styleCode';
                $params['styleCode'] = $style;
            }
        }
        $sql .= ' LIMIT ' . $limit;

        return $this->connection->query($sql, $params, self::REPO, 'findAccessories');
    }

    public function findDescriptions(array $filter = []): array
    {
        if (!$this->tableExists('descript')) {
            return [];
        }
        $limit = max(1, min(500, (int) ($filter['maxRecords'] ?? 50)));
        $style = isset($filter['styleCode']) ? strtoupper(MigrationPayload::string($filter['styleCode'])) : null;
        $cols = $this->existingColumns('descript', ['id', 'style', 'style_code', 'description', 'descript', 'text']);
        $select = $cols === [] ? '*' : implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
        $sql = "SELECT {$select} FROM `descript` WHERE 1 = 1";
        $params = [];
        if ($style !== null && $style !== '') {
            if (in_array('style', $cols, true)) {
                $sql .= ' AND UPPER(`style`) = :styleCode';
                $params['styleCode'] = $style;
            } elseif (in_array('style_code', $cols, true)) {
                $sql .= ' AND UPPER(`style_code`) = :styleCode';
                $params['styleCode'] = $style;
            }
        }
        $sql .= ' LIMIT ' . $limit;

        return $this->connection->query($sql, $params, self::REPO, 'findDescriptions');
    }

    public function inspectSchema(): array
    {
        $tableRows = $this->connection->query('SHOW TABLES', [], self::REPO, 'inspectSchema.tables');
        $all = [];
        foreach ($tableRows as $row) {
            $name = MigrationPayload::string(array_values($row)[0] ?? '');
            if ($name !== '') {
                $all[] = $name;
            }
        }
        $tables = array_values(array_filter($all, static fn (string $t): bool => in_array($t, self::PRODUCT_TABLES, true)));
        $columns = [];
        $rowCounts = [];
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            $desc = $this->connection->query('DESCRIBE `' . $table . '`', [], self::REPO, 'inspectSchema.describe');
            $columns[$table] = array_values(array_filter(array_map(
                static fn (array $r): string => MigrationPayload::string($r['Field'] ?? ''),
                $desc,
            )));
            $cnt = $this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `' . $table . '`',
                [],
                self::REPO,
                'inspectSchema.count',
            );
            $rowCounts[$table] = (int) ($cnt[0]['cnt'] ?? 0);
        }

        return [
            'tables' => $tables,
            'columns' => $columns,
            'rowCounts' => $rowCounts,
        ];
    }

    public function findLatestBaseCost(string $itemCode, int $debtorCompanyCode): ?array
    {
        if (!$this->tableExists('price')) {
            return null;
        }

        $cols = $this->existingColumns('price', ['code', 'dbco', 'cost', 'dc']);
        if (!in_array('code', $cols, true) || !in_array('cost', $cols, true)) {
            return null;
        }

        $dbcoClause = in_array('dbco', $cols, true) ? ' AND `dbco` = :dbco' : '';
        $orderClause = in_array('dc', $cols, true) ? ' ORDER BY `dc` DESC' : '';
        $sql = 'SELECT `cost`' . (in_array('dc', $cols, true) ? ', `dc`' : '') .
            ' FROM `price` WHERE `code` = :code' . $dbcoClause . $orderClause . ' LIMIT 1';
        $params = ['code' => $itemCode];
        if ($dbcoClause !== '') {
            $params['dbco'] = $debtorCompanyCode;
        }

        $rows = $this->connection->query($sql, $params, self::REPO, 'findLatestBaseCost');
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $cost = MigrationPayload::string($row['cost'] ?? '');
        if ($cost === '' || !is_numeric($cost)) {
            return null;
        }

        $lastUpdated = null;
        if (isset($row['dc']) && $row['dc'] !== null && $row['dc'] !== '') {
            $lastUpdated = MigrationPayload::string($row['dc']);
        }

        return [
            'cost' => $cost,
            'lastUpdatedUtc' => $lastUpdated,
        ];
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        $rows = $this->connection->query(
            'SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName',
            ['tableName' => $table],
            self::REPO,
            'tableExists',
        );

        return $rows !== [];
    }

    /**
     * @param list<string> $wanted
     * @return list<string>
     */
    private function existingColumns(string $table, array $wanted): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }
        $rows = $this->connection->query(
            'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName',
            ['tableName' => $table],
            self::REPO,
            'existingColumns',
        );
        $have = [];
        foreach ($rows as $row) {
            $have[] = MigrationPayload::string($row['name'] ?? '');
        }
        $out = [];
        foreach ($wanted as $col) {
            if (in_array($col, $have, true)) {
                $out[] = $col;
            }
        }

        return $out;
    }
}
