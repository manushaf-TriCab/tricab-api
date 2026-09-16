<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Technical;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Api\Tests\Payload;

/**
 * Phase 2 content integrity + legacy parity fixtures for Technical lookup tables.
 */
final class TechnicalParityAndIntegrityTest extends TestCase
{
    private TechnicalContentRepository $repo;

    protected function setUp(): void
    {
        $this->repo = TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2));
    }

    public function test_parity_fixtures_match_published_legacy_values(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/technical/v1';
        $fixtures = json_decode((string) file_get_contents($root . '/parity-fixtures.json'), true);
        self::assertIsArray($fixtures);
        self::assertIsArray($fixtures['fixtures']);

        foreach ($fixtures['fixtures'] as $fixture) {
            self::assertIsArray($fixture);
            $tableKey = Payload::str($fixture['tableKey']);
            $table = $this->repo->table($tableKey);
            $actual = $this->resolveFixturePath($table, Payload::str($fixture['path']));
            self::assertEquals(
                $fixture['expected'],
                $actual,
                'Parity fixture failed: ' . Payload::str($fixture['id'] ?? $tableKey),
            );
        }
    }

    public function test_phase2_articles_are_loadable(): void
    {
        $slugs = [
            'lugging',
            'lszh',
            'drum-handling',
            'fire-installation',
            'fire-testing',
            'industrial-standards',
            'fire-performance',
            'sbo-general-installation',
            'sbo-standards',
            'industrial-general-installation',
        ];
        foreach ($slugs as $slug) {
            $article = $this->repo->article($slug);
            self::assertNotSame('', $article['title'], $slug);
            self::assertIsArray($article['blocks']);
            self::assertNotEmpty($article['blocks'], $slug);
        }
    }

    public function test_lookup_tables_have_consistent_column_widths(): void
    {
        $keys = [
            'voltage-drop',
            'earth-conductor-size',
            'conduit-sizing',
            'impedance',
            'conductor-data',
            'current-iec',
            'current-ieee',
            'mud-immersion',
            'cable-designation',
        ];

        foreach ($keys as $key) {
            $table = $this->repo->table($key);
            if (($table['variant'] ?? null) === 'reference_rows') {
                self::assertIsArray($table['columns'] ?? null, $key);
                self::assertIsArray($table['groups'] ?? null, $key);
                continue;
            }

            $datasets = [];
            if (isset($table['datasets']) && is_array($table['datasets'])) {
                foreach ($table['datasets'] as $datasetKey => $dataset) {
                    self::assertIsArray($dataset, $key . ':' . $datasetKey);
                    $datasets[(string) $datasetKey] = $dataset;
                }
                self::assertNotEmpty($datasets, $key);
            } else {
                $datasets['default'] = $table;
            }

            foreach ($datasets as $datasetKey => $dataset) {
                // Some datasets (e.g. conductor-data) nest multiple tables under "tables".
                $tableSets = [];
                if (isset($dataset['tables']) && is_array($dataset['tables'])) {
                    foreach ($dataset['tables'] as $nestedKey => $nested) {
                        if (is_array($nested)) {
                            $tableSets[(string) $nestedKey] = $nested;
                        }
                    }
                } else {
                    $tableSets['grid'] = $dataset;
                }

                foreach ($tableSets as $nestedKey => $grid) {
                    if (($grid['variant'] ?? null) === 'reference_rows' || isset($grid['formulas'])) {
                        continue;
                    }
                    $columnIds = $this->flatColumnIds(Payload::obj($grid));
                    if ($columnIds === [] && isset($grid['columns']) && is_array($grid['columns'])) {
                        foreach ($grid['columns'] as $column) {
                            if (is_array($column) && isset($column['id']) && is_string($column['id'])) {
                                $columnIds[] = $column['id'];
                            }
                        }
                    }
                    self::assertNotEmpty($columnIds, $key . ':' . $datasetKey . ':' . $nestedKey);
                    $rows = $grid['rows'] ?? [];
                    self::assertIsArray($rows);
                    self::assertNotEmpty($rows, $key . ':' . $datasetKey . ':' . $nestedKey);
                    foreach ($rows as $index => $row) {
                        self::assertIsArray($row);
                        self::assertArrayHasKey('values', $row);
                        self::assertIsArray($row['values']);
                        foreach ($columnIds as $columnId) {
                            self::assertArrayHasKey(
                                $columnId,
                                $row['values'],
                                sprintf(
                                    '%s:%s:%s row %d missing column %s',
                                    $key,
                                    $datasetKey,
                                    $nestedKey,
                                    $index,
                                    $columnId,
                                ),
                            );
                        }
                    }
                }
            }
        }
    }

    public function test_manifest_marks_phase2_content_available(): void
    {
        $sections = $this->repo->sections();
        $byId = [];
        foreach ($sections['sections'] as $section) {
            foreach (Payload::objList($section['items']) as $item) {
                $byId[Payload::str($item['id'])] = $item;
            }
        }

        foreach ([
            'lszh',
            'drum-handling',
            'earth-conductor-size',
            'conduit-sizing',
            'impedance',
            'mud-immersion',
            'fire-perf',
        ] as $id) {
            self::assertSame('AVAILABLE', Payload::obj($byId[$id])['status'], $id);
        }

        // Phase 4H migrated document libraries are AVAILABLE in the manifest.
        foreach ([
            'warranty-certificates',
            'certs',
            'sbo-certs',
            'acc-warranty-certificates',
            'installation-guides',
        ] as $id) {
            self::assertSame('AVAILABLE', Payload::obj($byId[$id])['status'], $id);
        }
        foreach (['bep-pvc-disposal', 'bep-pvc-certificate', 'acc-certs'] as $id) {
            self::assertSame('COMING_SOON', Payload::obj($byId[$id])['status'], $id);
        }
        self::assertSame('industrial-standards', Payload::obj($byId['standards'])['contentKey']);
        self::assertSame('impedance', Payload::obj($byId['impedance'])['tableKey']);
    }

    /**
     * @param array<string, mixed> $table
     */
    private function resolveFixturePath(array $table, string $path): mixed
    {
        // Supported path forms used in parity-fixtures.json
        if (preg_match('/^rows\[csaMm2=([0-9.]+)\]\.values\.([a-z0-9_]+)$/', $path, $m) === 1) {
            $row = $this->findRowByCsa($table['rows'] ?? [], (float) $m[1]);
            self::assertNotNull($row, $path);

            return Payload::obj($row['values'])[$m[2]] ?? null;
        }

        if (preg_match('/^datasets\.([a-z0-9_]+)\.rows\[csaMm2=([0-9.]+)\]\.values$/', $path, $m) === 1) {
            $dataset = Payload::obj($table['datasets'])[$m[1]] ?? null;
            self::assertIsArray($dataset, $path);
            $row = $this->findRowByCsa($dataset['rows'] ?? [], (float) $m[2]);
            self::assertNotNull($row, $path);

            return $row['values'] ?? null;
        }

        if (preg_match('/^datasets\.([a-z0-9_]+)\.rows\[rowKey=([A-Za-z0-9]+)\]\.values\.([a-z0-9_]+)$/', $path, $m) === 1) {
            $dataset = Payload::obj($table['datasets'])[$m[1]] ?? null;
            self::assertIsArray($dataset, $path);
            $row = $this->findRowByKey($dataset['rows'] ?? [], $m[2]);
            self::assertNotNull($row, $path);

            return Payload::obj($row['values'])[$m[3]] ?? null;
        }

        if (preg_match("/^datasets\\.([a-z0-9_]+)\\.rows\\[productCode='([^']+)'\\]\\.values\\.([a-z0-9_]+)$/", $path, $m) === 1) {
            $dataset = Payload::obj($table['datasets'])[$m[1]] ?? null;
            self::assertIsArray($dataset, $path);
            $row = $this->findRowByProductCode($dataset['rows'] ?? [], $m[2]);
            self::assertNotNull($row, $path);

            return Payload::obj($row['values'])[$m[3]] ?? null;
        }

        if (preg_match("/^groups\\[(\\d+)\\]\\.rows\\[testFluid='([^']+)'\\]\\.values$/", $path, $m) === 1) {
            $group = Payload::list($table['groups'])[(int) $m[1]] ?? null;
            self::assertIsArray($group, $path);
            foreach (Payload::list($group['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $values = $row['values'] ?? null;
                if (!is_array($values)) {
                    continue;
                }
                if (($values['testFluid'] ?? null) === $m[2]) {
                    return $values;
                }
            }
            self::fail('Mud row not found for ' . $path);
        }

        // Conductor-data nested: datasets.general.tables.constructionMetric.rows[rowKey='0.50'].values
        if (preg_match(
            "/^datasets\\.([a-z0-9_-]+)\\.tables\\.([a-zA-Z0-9_]+)\\.rows\\[rowKey='([^']+)'\\]\\.values$/",
            $path,
            $m,
        ) === 1) {
            $dataset = Payload::obj($table['datasets'])[$m[1]] ?? null;
            self::assertIsArray($dataset, $path);
            $nested = Payload::obj($dataset['tables'])[$m[2]] ?? null;
            self::assertIsArray($nested, $path);
            $row = $this->findRowByKey($nested['rows'] ?? [], $m[3]);
            self::assertNotNull($row, $path);

            return $row['values'] ?? null;
        }

        self::fail('Unsupported fixture path: ' . $path);
    }

    public function test_general_conductor_data_row_counts_and_partition(): void
    {
        $table = $this->repo->table('conductor-data');
        $general = Payload::obj($table['datasets'])['general'] ?? null;
        self::assertIsArray($general);
        self::assertArrayNotHasKey('blockedTables', $general);

        $counts = $general['sourceRowCounts'] ?? null;
        self::assertIsArray($counts);
        self::assertSame(46, Payload::int($counts['total']));
        self::assertSame(24, Payload::int($counts['metric']));
        self::assertSame(22, Payload::int($counts['awg']));
        self::assertSame(46, Payload::int($counts['metric']) + Payload::int($counts['awg']));

        $construction = Payload::obj(Payload::obj($general['tables'])['constructionMetric'])['rows'] ?? null;
        $dc = Payload::obj(Payload::obj($general['tables'])['dcResistanceMetric'])['rows'] ?? null;
        $sccCu = Payload::obj(Payload::obj($general['tables'])['shortCircuitCopperMetric'])['rows'] ?? null;
        $sccAl = Payload::obj(Payload::obj($general['tables'])['shortCircuitAluminiumMetric'])['rows'] ?? null;
        $awg = Payload::obj(Payload::obj($general['tables'])['shortCircuitAwg'])['rows'] ?? null;
        self::assertIsArray($construction);
        self::assertIsArray($dc);
        self::assertIsArray($sccCu);
        self::assertIsArray($sccAl);
        self::assertIsArray($awg);
        self::assertCount(24, $construction);
        self::assertCount(24, $dc);
        self::assertCount(24, $sccCu);
        self::assertCount(24, $sccAl);
        self::assertCount(22, $awg);

        $metricKeys = [];
        foreach ($construction as $row) {
            self::assertIsArray($row);
            $key = Payload::str($row['rowKey'] ?? '');
            self::assertNotSame('', $key);
            self::assertArrayNotHasKey($key, $metricKeys, 'duplicate metric rowKey ' . $key);
            $metricKeys[$key] = true;
        }
        self::assertCount(24, $metricKeys);

        $awgKeys = [];
        foreach ($awg as $row) {
            self::assertIsArray($row);
            $key = Payload::str($row['rowKey'] ?? '');
            self::assertNotSame('', $key);
            self::assertArrayNotHasKey($key, $awgKeys, 'duplicate awg rowKey ' . $key);
            $awgKeys[$key] = true;
        }
        self::assertCount(22, $awgKeys);

        // Industrial dataset must remain present and non-empty.
        $industrial = Payload::obj($table['datasets'])['industrial'] ?? null;
        self::assertIsArray($industrial);
        self::assertNotEmpty($industrial['tables'] ?? []);
    }

    /**
     * @param mixed $rows
     * @return array<string, mixed>|null
     */
    private function findRowByCsa(mixed $rows, float $csa): ?array
    {
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && Payload::float($row['csaMm2'] ?? NAN) === $csa) {
                return Payload::obj($row);
            }
        }

        return null;
    }

    /**
     * @param mixed $rows
     * @return array<string, mixed>|null
     */
    private function findRowByKey(mixed $rows, string $rowKey): ?array
    {
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && Payload::str($row['rowKey'] ?? '') === $rowKey) {
                return Payload::obj($row);
            }
        }

        return null;
    }

    /**
     * @param mixed $rows
     * @return array<string, mixed>|null
     */
    private function findRowByProductCode(mixed $rows, string $code): ?array
    {
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (Payload::str($row['productCode'] ?? '') === $code) {
                return Payload::obj($row);
            }
            if (Payload::str($row['label'] ?? '') === $code) {
                return Payload::obj($row);
            }
            if (Payload::str($row['rowKey'] ?? '') === $code) {
                return Payload::obj($row);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dataset
     * @return list<string>
     */
    private function flatColumnIds(array $dataset): array
    {
        $ids = [];
        $groups = $dataset['columnGroups'] ?? [];
        if (!is_array($groups)) {
            return $ids;
        }
        foreach ($groups as $group) {
            if (!is_array($group) || !isset($group['columns']) || !is_array($group['columns'])) {
                continue;
            }
            foreach ($group['columns'] as $column) {
                if (is_array($column) && isset($column['id']) && is_string($column['id'])) {
                    $ids[] = $column['id'];
                }
            }
        }

        return $ids;
    }
}
