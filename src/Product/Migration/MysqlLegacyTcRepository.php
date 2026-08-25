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

    public function aggregatePriceContextByDbco(int $dbco): ?array
    {
        if (!$this->tableExists('price')) {
            return null;
        }

        $cols = $this->existingColumns('price', ['dbco', 'code']);
        if (!in_array('dbco', $cols, true)) {
            return null;
        }

        $sql = 'SELECT COUNT(*) AS price_count';
        if (in_array('code', $cols, true)) {
            $sql .= ', COUNT(DISTINCT `code`) AS code_count';
        }
        $sql .= ' FROM `price` WHERE `dbco` = :dbco GROUP BY `dbco`';

        $rows = $this->connection->query($sql, ['dbco' => $dbco], self::REPO, 'aggregatePriceContextByDbco');
        if ($rows === []) {
            return [
                'dbco' => $dbco,
                'priceRowCount' => 0,
                'distinctCodeCount' => 0,
            ];
        }

        $row = $rows[0];

        return [
            'dbco' => $dbco,
            'priceRowCount' => (int) ($row['price_count'] ?? 0),
            'distinctCodeCount' => (int) ($row['code_count'] ?? 0),
        ];
    }

    public function inspectPricingCode(string $code, int $dbco): ?array
    {
        $batch = $this->inspectPricingCodes([$code], $dbco);

        return $batch[$code] ?? [
            'exists' => false,
            'revisionCount' => 0,
            'latestDc' => null,
            'latestCost' => null,
            'zeroCost' => false,
            'duplicateLatestDc' => false,
        ];
    }

    public function inspectPricingCodes(array $codes, int $dbco): array
    {
        if (!$this->tableExists('price') || $codes === []) {
            return [];
        }

        $cols = $this->existingColumns('price', ['code', 'dbco', 'cost', 'dc']);
        if (!in_array('code', $cols, true)) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $codes))), 100) as $chunk) {
            $placeholders = [];
            $params = ['dbco' => $dbco];
            foreach ($chunk as $index => $code) {
                $key = 'code' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $code;
            }

            $dbcoClause = in_array('dbco', $cols, true) ? ' AND `dbco` = :dbco' : '';
            $countSql = 'SELECT `code`, COUNT(*) AS cnt FROM `price` WHERE `code` IN (' . implode(', ', $placeholders) . ')' .
                $dbcoClause . ' GROUP BY `code`';
            $countRows = $this->connection->query($countSql, $params, self::REPO, 'inspectPricingCodes.count');

            $counts = [];
            foreach ($countRows as $row) {
                $counts[MigrationPayload::string($row['code'] ?? '')] = (int) ($row['cnt'] ?? 0);
            }

            foreach ($chunk as $code) {
                $revisionCount = $counts[$code] ?? 0;
                if ($revisionCount === 0) {
                    $out[$code] = [
                        'exists' => false,
                        'revisionCount' => 0,
                        'latestDc' => null,
                        'latestCost' => null,
                        'zeroCost' => false,
                        'duplicateLatestDc' => false,
                    ];
                    continue;
                }

                $codeParams = ['code' => $code];
                if ($dbcoClause !== '') {
                    $codeParams['dbco'] = $dbco;
                }

                $latestDc = null;
                $latestCost = null;
                $duplicateLatestDc = false;
                $zeroCost = false;

                if (in_array('dc', $cols, true)) {
                    $maxSql = 'SELECT MAX(`dc`) AS max_dc FROM `price` WHERE `code` = :code' . $dbcoClause;
                    $maxRows = $this->connection->query($maxSql, $codeParams, self::REPO, 'inspectPricingCodes.maxDc');
                    $latestDc = MigrationPayload::string($maxRows[0]['max_dc'] ?? '');

                    $dupParams = $codeParams;
                    $dupParams['latestDc'] = $latestDc;
                    $dupSql = 'SELECT COUNT(*) AS cnt, COUNT(DISTINCT `cost`) AS distinct_costs FROM `price` WHERE `code` = :code' .
                        $dbcoClause . ' AND `dc` = :latestDc';
                    $dupRows = $this->connection->query($dupSql, $dupParams, self::REPO, 'inspectPricingCodes.dupLatest');
                    $dupCount = (int) ($dupRows[0]['cnt'] ?? 0);
                    $distinctCosts = (int) ($dupRows[0]['distinct_costs'] ?? 0);
                    $duplicateLatestDc = $dupCount > 1 && $distinctCosts > 1;

                    $latestSql = 'SELECT `cost` FROM `price` WHERE `code` = :code' . $dbcoClause . ' ORDER BY `dc` DESC LIMIT 1';
                } else {
                    $latestSql = 'SELECT `cost` FROM `price` WHERE `code` = :code' . $dbcoClause . ' LIMIT 1';
                }

                $latestRows = $this->connection->query($latestSql, $codeParams, self::REPO, 'inspectPricingCodes.latest');
                $latestCost = MigrationPayload::string($latestRows[0]['cost'] ?? '');

                if ($latestCost !== '' && is_numeric($latestCost) && bccomp($latestCost, '0', 6) === 0) {
                    $zeroCost = true;
                }

                $out[$code] = [
                    'exists' => true,
                    'revisionCount' => $revisionCount,
                    'latestDc' => $latestDc !== '' ? $latestDc : null,
                    'latestCost' => $latestCost !== '' && is_numeric($latestCost) ? $latestCost : null,
                    'zeroCost' => $zeroCost,
                    'duplicateLatestDc' => $duplicateLatestDc,
                ];
            }
        }

        return $out;
    }

    public function discoverProductRelatedTables(): array
    {
        $tableRows = $this->connection->query('SHOW TABLES', [], self::REPO, 'discoverProductRelatedTables.tables');
        $all = [];
        foreach ($tableRows as $row) {
            $name = MigrationPayload::string(array_values($row)[0] ?? '');
            if ($name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1) {
                $all[] = $name;
            }
        }

        $out = [];
        foreach ($all as $table) {
            if (!$this->isProductRelatedTable($table)) {
                continue;
            }
            $desc = $this->connection->query('DESCRIBE `' . $table . '`', [], self::REPO, 'discoverProductRelatedTables.describe');
            $columns = [];
            $pk = [];
            foreach ($desc as $col) {
                $field = MigrationPayload::string($col['Field'] ?? '');
                if ($field === '') {
                    continue;
                }
                $columns[] = $field;
                if (strtoupper(MigrationPayload::string($col['Key'] ?? '')) === 'PRI') {
                    $pk[] = $field;
                }
            }
            $cnt = $this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `' . $table . '`',
                [],
                self::REPO,
                'discoverProductRelatedTables.count',
            );
            $out[] = [
                'table' => $table,
                'rowCount' => (int) ($cnt[0]['cnt'] ?? 0),
                'columns' => $columns,
                'primaryKey' => $pk,
            ];
        }

        return $out;
    }

    public function discoverTproVsTproExt(): array
    {
        $tproExists = $this->tableExists('tpro');
        $extExists = $this->tableExists('tpro_ext');
        if (!$tproExists || !$extExists) {
            return [
                'tproExists' => $tproExists,
                'tproExtExists' => $extExists,
                'joinKey' => null,
                'fields' => [],
                'onlyInTpro' => 0,
                'onlyInTproExt' => 0,
                'sharedRows' => 0,
            ];
        }

        $tproCols = $this->allColumns('tpro');
        $extCols = $this->allColumns('tpro_ext');
        $joinTpro = null;
        $joinExt = null;
        if (in_array('ida', $tproCols, true) && in_array('product_code', $extCols, true)) {
            $joinTpro = 'ida';
            $joinExt = 'product_code';
        } elseif (in_array('product_code', $tproCols, true) && in_array('product_code', $extCols, true)) {
            $joinTpro = 'product_code';
            $joinExt = 'product_code';
        }

        $tproCount = (int) ($this->connection->query('SELECT COUNT(*) AS cnt FROM `tpro`', [], self::REPO, 'discoverTproVsTproExt.tproCount')[0]['cnt'] ?? 0);
        $extCount = (int) ($this->connection->query('SELECT COUNT(*) AS cnt FROM `tpro_ext`', [], self::REPO, 'discoverTproVsTproExt.extCount')[0]['cnt'] ?? 0);
        $sharedRows = 0;
        $onlyTpro = 0;
        $onlyExt = 0;
        $fields = [];
        if ($joinTpro !== null && $joinExt !== null) {
            $sharedRows = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro` t INNER JOIN `tpro_ext` e ON t.`' . $joinTpro . '` = e.`' . $joinExt . '`',
                [],
                self::REPO,
                'discoverTproVsTproExt.shared',
            )[0]['cnt'] ?? 0);
            $onlyTpro = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro` t LEFT JOIN `tpro_ext` e ON t.`' . $joinTpro . '` = e.`' . $joinExt . '` WHERE e.`' . $joinExt . '` IS NULL',
                [],
                self::REPO,
                'discoverTproVsTproExt.onlyTpro',
            )[0]['cnt'] ?? 0);
            $onlyExt = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro_ext` e LEFT JOIN `tpro` t ON t.`' . $joinTpro . '` = e.`' . $joinExt . '` WHERE t.`' . $joinTpro . '` IS NULL',
                [],
                self::REPO,
                'discoverTproVsTproExt.onlyExt',
            )[0]['cnt'] ?? 0);

            $pairs = [
                ['tpro' => 'col', 'ext' => 'colour', 'name' => 'colour'],
                ['tpro' => 'uwt', 'ext' => 'weight', 'name' => 'weight'],
                ['tpro' => 'nm', 'ext' => 'desc2', 'name' => 'description'],
                ['tpro' => 'br', 'ext' => 'armour', 'name' => 'braid_or_armour'],
            ];
            foreach ($pairs as $pair) {
                if (!in_array($pair['tpro'], $tproCols, true) || !in_array($pair['ext'], $extCols, true)) {
                    continue;
                }
                $sql = 'SELECT'
                    . ' SUM(CASE WHEN t.`' . $pair['tpro'] . '` IS NOT NULL AND CAST(t.`' . $pair['tpro'] . '` AS CHAR) <> \'\' THEN 1 ELSE 0 END) AS tpro_coverage'
                    . ', SUM(CASE WHEN e.`' . $pair['ext'] . '` IS NOT NULL AND CAST(e.`' . $pair['ext'] . '` AS CHAR) <> \'\' THEN 1 ELSE 0 END) AS ext_coverage'
                    . ', SUM(CASE WHEN t.`' . $pair['tpro'] . '` IS NOT NULL AND e.`' . $pair['ext'] . '` IS NOT NULL AND CAST(t.`' . $pair['tpro'] . '` AS CHAR) <> CAST(e.`' . $pair['ext'] . '` AS CHAR) THEN 1 ELSE 0 END) AS conflicts'
                    . ' FROM `tpro` t INNER JOIN `tpro_ext` e ON t.`' . $joinTpro . '` = e.`' . $joinExt . '`';
                $row = $this->connection->query($sql, [], self::REPO, 'discoverTproVsTproExt.field')[0] ?? [];
                $tproCov = (int) ($row['tpro_coverage'] ?? 0);
                $extCov = (int) ($row['ext_coverage'] ?? 0);
                $conflicts = (int) ($row['conflicts'] ?? 0);
                $authority = 'AMBIGUOUS';
                if ($extCov > 0 && $conflicts === 0 && $extCov >= $tproCov) {
                    $authority = 'tpro_ext';
                } elseif ($tproCov > 0 && $conflicts === 0) {
                    $authority = 'tpro';
                }
                $fields[] = [
                    'field' => $pair['name'],
                    'tproColumn' => $pair['tpro'],
                    'tproExtColumn' => $pair['ext'],
                    'tproCoverage' => $tproCov,
                    'tproExtCoverage' => $extCov,
                    'conflicts' => $conflicts,
                    'recommendedAuthority' => $authority,
                ];
            }
        }

        return [
            'tproExists' => true,
            'tproExtExists' => true,
            'tproRowCount' => $tproCount,
            'tproExtRowCount' => $extCount,
            'joinKey' => $joinTpro !== null ? 'tpro.' . $joinTpro . ' = tpro_ext.' . $joinExt : null,
            'sharedRows' => $sharedRows,
            'onlyInTpro' => $onlyTpro,
            'onlyInTproExt' => $onlyExt,
            'tproColumns' => $tproCols,
            'tproExtColumns' => $extCols,
            'fields' => $fields,
        ];
    }

    public function discoverVariantCountsByStyle(): array
    {
        if ($this->tableExists('tpro_ext') && in_array('style', $this->allColumns('tpro_ext'), true)) {
            $codeCol = in_array('product_code', $this->allColumns('tpro_ext'), true) ? 'product_code' : 'style';

            return $this->connection->query(
                'SELECT `style` AS style_code, COUNT(*) AS variant_count, SUBSTRING_INDEX(GROUP_CONCAT(`' . $codeCol . '` ORDER BY `' . $codeCol . '` SEPARATOR \',\'), \',\', 5) AS example_codes FROM `tpro_ext` WHERE `style` IS NOT NULL AND `style` <> \'\' GROUP BY `style` ORDER BY variant_count DESC',
                [],
                self::REPO,
                'discoverVariantCountsByStyle.tproExt',
            );
        }
        $cols = $this->existingColumns('tpro', ['ida', 'style', 'product_code']);
        if (in_array('style', $cols, true)) {
            $sql = 'SELECT `style` AS style_code, COUNT(*) AS variant_count, SUBSTRING_INDEX(GROUP_CONCAT(`ida` ORDER BY `ida` SEPARATOR \',\'), \',\', 5) AS example_codes FROM `tpro` GROUP BY `style` ORDER BY variant_count DESC';
            if (!in_array('ida', $cols, true)) {
                $codeCol = in_array('product_code', $cols, true) ? 'product_code' : 'style';
                $sql = 'SELECT `style` AS style_code, COUNT(*) AS variant_count, SUBSTRING_INDEX(GROUP_CONCAT(`' . $codeCol . '` ORDER BY `' . $codeCol . '` SEPARATOR \',\'), \',\', 5) AS example_codes FROM `tpro` GROUP BY `style` ORDER BY variant_count DESC';
            }
        } elseif (in_array('ida', $cols, true)) {
            $sql = 'SELECT UPPER(SUBSTRING(`ida`, 1, 3)) AS style_code, COUNT(*) AS variant_count, SUBSTRING_INDEX(GROUP_CONCAT(`ida` ORDER BY `ida` SEPARATOR \',\'), \',\', 5) AS example_codes FROM `tpro` GROUP BY UPPER(SUBSTRING(`ida`, 1, 3)) ORDER BY variant_count DESC';
        } else {
            return [];
        }

        return $this->connection->query($sql, [], self::REPO, 'discoverVariantCountsByStyle');
    }

    public function discoverSpecLabels(): array
    {
        if (!$this->tableExists('spec_labels')) {
            return [];
        }
        $cols = $this->allColumns('spec_labels');
        $select = $cols === [] ? '*' : implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));

        return $this->connection->query(
            'SELECT ' . $select . ' FROM `spec_labels` LIMIT 2000',
            [],
            self::REPO,
            'discoverSpecLabels',
        );
    }

    public function discoverStyleCodes(): array
    {
        $out = [];
        if ($this->tableExists('tpro') && in_array('style', $this->allColumns('tpro'), true)) {
            foreach ($this->connection->query('SELECT DISTINCT `style` AS style_code FROM `tpro` WHERE `style` IS NOT NULL AND `style` <> \'\'', [], self::REPO, 'discoverStyleCodes.tpro') as $row) {
                $out[] = ['style_code' => MigrationPayload::string($row['style_code'] ?? ''), 'source' => 'tpro.style'];
            }
        }
        if ($this->tableExists('tpro_ext') && in_array('style', $this->allColumns('tpro_ext'), true)) {
            foreach ($this->connection->query('SELECT DISTINCT `style` AS style_code FROM `tpro_ext` WHERE `style` IS NOT NULL AND `style` <> \'\'', [], self::REPO, 'discoverStyleCodes.tproExt') as $row) {
                $out[] = ['style_code' => MigrationPayload::string($row['style_code'] ?? ''), 'source' => 'tpro_ext.style'];
            }
        }
        if ($this->tableExists('cable_specs') && in_array('cable_style', $this->allColumns('cable_specs'), true)) {
            foreach ($this->connection->query('SELECT DISTINCT `cable_style` AS style_code FROM `cable_specs` WHERE `cable_style` IS NOT NULL AND `cable_style` <> \'\'', [], self::REPO, 'discoverStyleCodes.cable') as $row) {
                $out[] = ['style_code' => MigrationPayload::string($row['style_code'] ?? ''), 'source' => 'cable_specs.cable_style'];
            }
        }

        return $out;
    }

    public function discoverColumnValueCounts(string $table, string $column, int $limit = 200): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return [];
        }
        if (!$this->tableExists($table)) {
            return [];
        }
        if (!in_array($column, $this->allColumns($table), true)) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT CAST(`' . $column . '` AS CHAR) AS value, COUNT(*) AS usage_count FROM `' . $table . '` WHERE `' . $column . '` IS NOT NULL AND CAST(`' . $column . '` AS CHAR) <> \'\' GROUP BY CAST(`' . $column . '` AS CHAR) ORDER BY usage_count DESC LIMIT ' . $limit;

        return $this->connection->query($sql, [], self::REPO, 'discoverColumnValueCounts');
    }

    public function discoverNumericCableStyles(): array
    {
        if (!$this->tableExists('cable_specs') || !in_array('cable_style', $this->allColumns('cable_specs'), true)) {
            return [];
        }

        return $this->connection->query(
            <<<'SQL'
SELECT
    CAST(`cable_style` AS CHAR) AS cable_style,
    COUNT(*) AS row_count,
    CASE
        WHEN CAST(`cable_style` AS CHAR) REGEXP '^[0-9]+$' THEN 'numeric'
        ELSE 'non_numeric'
    END AS style_kind
FROM `cable_specs`
WHERE `cable_style` IS NOT NULL AND CAST(`cable_style` AS CHAR) <> ''
GROUP BY CAST(`cable_style` AS CHAR)
HAVING style_kind = 'numeric'
ORDER BY CAST(cable_style AS UNSIGNED) ASC
LIMIT 500
SQL,
            [],
            self::REPO,
            'discoverNumericCableStyles',
        );
    }

    public function discoverAccessoryIdentitySummary(): array
    {
        $out = [
            'accessorySpecsRows' => 0,
            'tproExtAccessoryStyles' => 0,
            'exactProductCodeJoin' => 0,
            'stylesInAccessorySpecsViaTproExt' => 0,
            'joinStrategy' => 'accessory_specs.product_code = tpro_ext.product_code → tpro_ext.style; do not assume WP style_code = tpro_ext.style',
            'status' => 'REVIEW',
            'sampleAccessorySpecCodes' => [],
            'sampleTproExtCodes' => [],
            'trimmedCaseJoin' => 0,
            'outcome' => 'EXPLICIT_MAPPING_REQUIRED',
        ];
        if (!$this->tableExists('accessory_specs')) {
            return $out;
        }
        $out['accessorySpecsRows'] = (int) ($this->connection->query(
            'SELECT COUNT(*) AS cnt FROM `accessory_specs`',
            [],
            self::REPO,
            'discoverAccessoryIdentitySummary.count',
        )[0]['cnt'] ?? 0);

        if (in_array('product_code', $this->allColumns('accessory_specs'), true)) {
            $out['sampleAccessorySpecCodes'] = array_map(
                static fn (array $r): string => MigrationPayload::string($r['product_code'] ?? ''),
                $this->connection->query(
                    'SELECT `product_code` FROM `accessory_specs` WHERE `product_code` IS NOT NULL AND `product_code` <> \'\' ORDER BY `product_code` ASC LIMIT 10',
                    [],
                    self::REPO,
                    'discoverAccessoryIdentitySummary.sampleAcc',
                ),
            );
        }

        if ($this->tableExists('tpro_ext')
            && in_array('product_code', $this->allColumns('tpro_ext'), true)
            && in_array('style', $this->allColumns('tpro_ext'), true)
            && in_array('product_code', $this->allColumns('accessory_specs'), true)
        ) {
            $out['exactProductCodeJoin'] = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `accessory_specs` a INNER JOIN `tpro_ext` e ON a.`product_code` = e.`product_code`',
                [],
                self::REPO,
                'discoverAccessoryIdentitySummary.join',
            )[0]['cnt'] ?? 0);
            $out['trimmedCaseJoin'] = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `accessory_specs` a INNER JOIN `tpro_ext` e ON UPPER(TRIM(a.`product_code`)) = UPPER(TRIM(e.`product_code`))',
                [],
                self::REPO,
                'discoverAccessoryIdentitySummary.trimJoin',
            )[0]['cnt'] ?? 0);
            $out['stylesInAccessorySpecsViaTproExt'] = (int) ($this->connection->query(
                'SELECT COUNT(DISTINCT e.`style`) AS cnt FROM `accessory_specs` a INNER JOIN `tpro_ext` e ON a.`product_code` = e.`product_code` WHERE e.`style` IS NOT NULL AND e.`style` <> \'\'',
                [],
                self::REPO,
                'discoverAccessoryIdentitySummary.styles',
            )[0]['cnt'] ?? 0);
            $out['tproExtAccessoryStyles'] = (int) ($this->connection->query(
                'SELECT COUNT(DISTINCT `style`) AS cnt FROM `tpro_ext` WHERE `style` IS NOT NULL AND `style` <> \'\' AND `style` REGEXP \'^[A-Za-z]\'',
                [],
                self::REPO,
                'discoverAccessoryIdentitySummary.alphaStyles',
            )[0]['cnt'] ?? 0);
            $out['sampleTproExtCodes'] = array_map(
                static fn (array $r): string => MigrationPayload::string($r['product_code'] ?? ''),
                $this->connection->query(
                    'SELECT `product_code` FROM `tpro_ext` WHERE `product_code` IS NOT NULL AND `product_code` <> \'\' ORDER BY `product_code` ASC LIMIT 10',
                    [],
                    self::REPO,
                    'discoverAccessoryIdentitySummary.sampleExt',
                ),
            );
        }

        $out['outcome'] = ($out['exactProductCodeJoin'] > 0 || $out['trimmedCaseJoin'] > 0)
            ? 'EXACT'
            : 'EXPLICIT_MAPPING_REQUIRED';
        $out['status'] = $out['outcome'] === 'EXACT' ? 'REVIEW' : 'REVIEW';

        return $out;
    }

    public function discoverDvFieldMappings(int $limit = 5000): array
    {
        if (!$this->tableExists('spec_labels')) {
            return [];
        }
        $limit = max(1, min(10000, $limit));
        $hasStyle = in_array('style', $this->allColumns('spec_labels'), true);
        $hasDim = in_array('dimension', $this->allColumns('spec_labels'), true);
        $hasLabel = in_array('label', $this->allColumns('spec_labels'), true);
        if (!$hasDim || !$hasLabel) {
            return [];
        }

        $sql = <<<SQL
SELECT
    COALESCE(NULLIF(TRIM(`style`), ''), '*') AS style_key,
    `dimension` AS source_field,
    `label` AS legacy_label,
    COUNT(*) AS label_rows
FROM `spec_labels`
WHERE `dimension` REGEXP '^(d[1-7]|v[1-4])$'
GROUP BY style_key, `dimension`, `label`
ORDER BY style_key ASC, `dimension` ASC
LIMIT {$limit}
SQL;
        if (!$hasStyle) {
            $sql = <<<SQL
SELECT
    '*' AS style_key,
    `dimension` AS source_field,
    `label` AS legacy_label,
    COUNT(*) AS label_rows
FROM `spec_labels`
WHERE `dimension` REGEXP '^(d[1-7]|v[1-4])$'
GROUP BY `dimension`, `label`
ORDER BY `dimension` ASC
LIMIT {$limit}
SQL;
        }

        $rows = $this->connection->query($sql, [], self::REPO, 'discoverDvFieldMappings.labels');

        // Sample non-null value counts per field from accessory_specs (global evidence only).
        $examples = [];
        if ($this->tableExists('accessory_specs')) {
            $cols = $this->allColumns('accessory_specs');
            foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'v1', 'v2', 'v3', 'v4'] as $field) {
                if (!in_array($field, $cols, true)) {
                    continue;
                }
                $sample = $this->connection->query(
                    'SELECT CAST(`' . $field . '` AS CHAR) AS example, COUNT(*) AS usage_count FROM `accessory_specs` WHERE `' . $field . '` IS NOT NULL AND CAST(`' . $field . '` AS CHAR) <> \'\' GROUP BY CAST(`' . $field . '` AS CHAR) ORDER BY usage_count DESC LIMIT 3',
                    [],
                    self::REPO,
                    'discoverDvFieldMappings.sample',
                );
                $examples[$field] = $sample;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $field = MigrationPayload::string($row['source_field'] ?? '');
            $label = MigrationPayload::string($row['legacy_label'] ?? '');
            $style = MigrationPayload::string($row['style_key'] ?? '*');
            $status = 'REVIEW';
            $proposed = null;
            if ($style === '*') {
                $status = 'REVIEW';
                $proposed = null;
            } elseif ($label !== '') {
                $status = 'SUGGESTED';
                $proposed = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $label)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $proposed = trim(preg_replace('/\s+/', ' ', $proposed) ?? $proposed);
            }
            $example = '';
            if (isset($examples[$field][0]['example'])) {
                $example = MigrationPayload::string($examples[$field][0]['example']);
            }
            $out[] = [
                'style' => $style,
                'sourceField' => $field,
                'legacyLabel' => $label,
                'example' => $example,
                'proposedDefinition' => $proposed,
                'status' => $status,
                'labelRows' => (int) ($row['label_rows'] ?? 0),
                'globalFieldAssumptionForbidden' => $style === '*',
            ];
        }

        return $out;
    }

    public function discoverAccessorySpecScopeEvidence(): array
    {
        if (!$this->tableExists('accessory_specs') || !$this->tableExists('tpro_ext')) {
            return [];
        }
        $extCols = $this->allColumns('tpro_ext');
        $specCols = $this->allColumns('accessory_specs');
        if (!in_array('product_code', $extCols, true) || !in_array('style', $extCols, true) || !in_array('product_code', $specCols, true)) {
            return [];
        }

        $fields = [];
        foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'v1', 'v2', 'v3', 'v4', 'volts', 'imax', 'weight'] as $field) {
            if (!in_array($field, $specCols, true)) {
                continue;
            }
            $sql = <<<SQL
SELECT
    e.`style` AS style_code,
    COUNT(DISTINCT CAST(a.`{$field}` AS CHAR)) AS distinct_values,
    COUNT(*) AS row_count
FROM `accessory_specs` a
INNER JOIN `tpro_ext` e ON e.`product_code` = a.`product_code`
WHERE e.`style` IS NOT NULL AND e.`style` <> ''
  AND a.`{$field}` IS NOT NULL AND CAST(a.`{$field}` AS CHAR) <> ''
GROUP BY e.`style`
HAVING row_count > 1
ORDER BY distinct_values DESC
LIMIT 200
SQL;
            $rows = $this->connection->query($sql, [], self::REPO, 'discoverAccessorySpecScopeEvidence');
            $productScope = 0;
            $variantScope = 0;
            foreach ($rows as $row) {
                if ((int) ($row['distinct_values'] ?? 0) <= 1) {
                    ++$productScope;
                } else {
                    ++$variantScope;
                }
            }
            $fields[] = [
                'sourceField' => $field,
                'stylesSampled' => count($rows),
                'candidateProductScopeStyles' => $productScope,
                'candidateVariantScopeStyles' => $variantScope,
                'proposedScope' => $variantScope > $productScope ? 'VARIANT' : ($productScope > 0 ? 'PRODUCT' : 'UNKNOWN'),
                'status' => 'REVIEW',
                'note' => 'Shared values may still be Variant scope by legacy semantics; operator confirmation required.',
            ];
        }

        return $fields;
    }

    public function discoverTproExclusionSummary(): array
    {
        $summary = [
            'tproRows' => 0,
            'tproExtRows' => 0,
            'joinedRows' => 0,
            'tproOnlyRows' => 0,
            'classificationRules' => [
                [
                    'rule' => 'HAS_TPRO_EXT',
                    'intent' => 'candidate ProductVariant',
                    'defaultStatus' => 'REVIEW',
                ],
                [
                    'rule' => 'TPRO_ONLY_NO_EXT',
                    'intent' => 'commerce/historical/stock item — not auto ProductVariant',
                    'defaultStatus' => 'EXCLUDED',
                ],
                [
                    'rule' => 'LEFT(tpro.ida,3) PREFIX',
                    'intent' => 'FORBIDDEN as Product identity',
                    'defaultStatus' => 'INVALID',
                ],
            ],
            'excludedCandidateCount' => 0,
            'reviewCandidateCount' => 0,
        ];
        if (!$this->tableExists('tpro')) {
            return $summary;
        }
        $summary['tproRows'] = (int) ($this->connection->query('SELECT COUNT(*) AS cnt FROM `tpro`', [], self::REPO, 'discoverTproExclusionSummary.tpro')[0]['cnt'] ?? 0);
        if (!$this->tableExists('tpro_ext')) {
            $summary['excludedCandidateCount'] = $summary['tproRows'];
            $summary['tproOnlyRows'] = $summary['tproRows'];

            return $summary;
        }
        $summary['tproExtRows'] = (int) ($this->connection->query('SELECT COUNT(*) AS cnt FROM `tpro_ext`', [], self::REPO, 'discoverTproExclusionSummary.ext')[0]['cnt'] ?? 0);
        $tproCols = $this->allColumns('tpro');
        $extCols = $this->allColumns('tpro_ext');
        if (in_array('ida', $tproCols, true) && in_array('product_code', $extCols, true)) {
            $summary['joinedRows'] = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro` t INNER JOIN `tpro_ext` e ON t.`ida` = e.`product_code`',
                [],
                self::REPO,
                'discoverTproExclusionSummary.joined',
            )[0]['cnt'] ?? 0);
            $summary['tproOnlyRows'] = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro` t LEFT JOIN `tpro_ext` e ON t.`ida` = e.`product_code` WHERE e.`product_code` IS NULL',
                [],
                self::REPO,
                'discoverTproExclusionSummary.only',
            )[0]['cnt'] ?? 0);
        }
        $summary['excludedCandidateCount'] = $summary['tproOnlyRows'];
        $summary['reviewCandidateCount'] = $summary['joinedRows'] > 0 ? $summary['joinedRows'] : $summary['tproExtRows'];

        return $summary;
    }

    public function discoverVariantReadinessSummary(): array
    {
        $out = [
            'totalRows' => 0,
            'withStyle' => 0,
            'withoutStyle' => 0,
            'duplicateProductCodes' => 0,
            'distinctStyles' => 0,
            'statusCounts' => [
                'READY' => 0,
                'REVIEW' => 0,
                'BLOCKED' => 0,
                'EXCLUDED' => 0,
            ],
        ];
        if (!$this->tableExists('tpro_ext')) {
            return $out;
        }
        $cols = $this->allColumns('tpro_ext');
        $out['totalRows'] = (int) ($this->connection->query('SELECT COUNT(*) AS cnt FROM `tpro_ext`', [], self::REPO, 'discoverVariantReadinessSummary.total')[0]['cnt'] ?? 0);
        if (in_array('style', $cols, true)) {
            $out['withStyle'] = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro_ext` WHERE `style` IS NOT NULL AND `style` <> \'\'',
                [],
                self::REPO,
                'discoverVariantReadinessSummary.withStyle',
            )[0]['cnt'] ?? 0);
            $out['withoutStyle'] = $out['totalRows'] - $out['withStyle'];
            $out['distinctStyles'] = (int) ($this->connection->query(
                'SELECT COUNT(DISTINCT `style`) AS cnt FROM `tpro_ext` WHERE `style` IS NOT NULL AND `style` <> \'\'',
                [],
                self::REPO,
                'discoverVariantReadinessSummary.styles',
            )[0]['cnt'] ?? 0);
        }
        if (in_array('product_code', $cols, true)) {
            $out['duplicateProductCodes'] = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM (SELECT `product_code` FROM `tpro_ext` WHERE `product_code` IS NOT NULL AND `product_code` <> \'\' GROUP BY `product_code` HAVING COUNT(*) > 1) d',
                [],
                self::REPO,
                'discoverVariantReadinessSummary.dupCodes',
            )[0]['cnt'] ?? 0);
        }

        // Without approved identity/classification/spec mappings, variants cannot be READY.
        $out['statusCounts']['BLOCKED'] = $out['withoutStyle'];
        $out['statusCounts']['REVIEW'] = max(0, $out['withStyle'] - $out['duplicateProductCodes']);
        $out['statusCounts']['READY'] = 0;
        if ($out['duplicateProductCodes'] > 0) {
            // Count rows belonging to duplicate codes as REVIEW collision.
            $dupRows = (int) ($this->connection->query(
                'SELECT COUNT(*) AS cnt FROM `tpro_ext` e WHERE e.`product_code` IN (SELECT `product_code` FROM `tpro_ext` WHERE `product_code` IS NOT NULL AND `product_code` <> \'\' GROUP BY `product_code` HAVING COUNT(*) > 1)',
                [],
                self::REPO,
                'discoverVariantReadinessSummary.dupRows',
            )[0]['cnt'] ?? 0);
            $out['statusCounts']['REVIEW'] = max(0, $out['withStyle'] - $dupRows);
            $out['statusCounts']['BLOCKED'] += $dupRows;
        }

        return $out;
    }

    private function isProductRelatedTable(string $table): bool
    {
        return (bool) preg_match('/tpro|cable|accessor|spec|descript|style|rating|product_/i', $table);
    }

    /**
     * @return list<string>
     */
    private function allColumns(string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }
        $rows = $this->connection->query(
            'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName ORDER BY ORDINAL_POSITION',
            ['tableName' => $table],
            self::REPO,
            'allColumns',
        );
        $out = [];
        foreach ($rows as $row) {
            $name = MigrationPayload::string($row['name'] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
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
