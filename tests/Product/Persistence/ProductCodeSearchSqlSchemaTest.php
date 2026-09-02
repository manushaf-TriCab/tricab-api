<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Persistence\AzureSqlProductCodeSearchRepository;
use TriCab\Api\Tests\CableCalculator\LiveAzureSqlSupport;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;

/**
 * The Phase 0 lesson, applied before the same mistake is made twice: the cable
 * calculator's resolver selected two columns that did not exist, every live call
 * failed with "Invalid column name", and the resolver's own catch hid it. Nothing
 * here executes in unit tests, so a wrong column name is invisible until production.
 *
 * So: check every column the generated SQL names against the committed DDL — which
 * runs everywhere, with no database — and execute the statement against Azure SQL
 * when one is configured.
 */
final class ProductCodeSearchSqlSchemaTest extends TestCase
{
    private const SCHEMA_SCRIPTS = [
        'database/scripts/development/create-product-schema.sql',
        'database/scripts/development/create-product-style-hierarchy-schema.sql',
        'database/scripts/development/create-product-code-index.sql',
    ];

    /** @var array<string, string> */
    private const ALIAS_TABLES = [
        'i' => 'ProductCodeIndex',
        'st' => 'ProductStyles',
        'p' => 'Products',
        'pst' => 'ProductStatuses',
        'v' => 'ProductVariants',
        'slug' => 'ProductSlugs',
        'sv' => 'ProductStyleSpecificationValues',
        'sd' => 'SpecificationDefinitions',
    ];

    /** Facet subqueries alias per field, so the table is read from the prefix. */
    private const ALIAS_PREFIX_TABLES = [
        'fv_' => 'ProductStyleSpecificationValues',
        'bv_' => 'ProductStyleSpecificationValues',
        'fd_' => 'SpecificationDefinitions',
        'bd_' => 'SpecificationDefinitions',
        'fo_' => 'SpecificationOptions',
    ];

    /** Projected by the OUTER APPLY that decorates a code with its loaded variant. */
    private const APPLY_COLUMNS = ['ProductName', 'VariantPublicId', 'VariantCode', 'Slug'];

    public function test_every_column_the_search_names_exists_in_the_schema(): void
    {
        $schema = self::parseSchema();

        foreach (self::generatedStatements() as $label => $sql) {
            $references = self::columnReferences($sql);
            self::assertNotEmpty($references, "No aliased column references found in {$label}.");

            foreach ($references as [$alias, $column]) {
                if ($alias === 'cat') {
                    self::assertContains(
                        $column,
                        self::APPLY_COLUMNS,
                        "{$label} selects cat.[{$column}], which the catalogue OUTER APPLY does not project.",
                    );

                    continue;
                }

                $table = self::tableFor($alias);
                self::assertNotNull($table, "{$label} uses alias '{$alias}'. Map it so its columns are checked.");
                self::assertArrayHasKey($table, $schema, "[product].[{$table}] is not in the committed DDL.");
                self::assertContains(
                    $column,
                    $schema[$table],
                    "{$label} reads {$alias}.[{$column}] but [product].[{$table}] has no such column.",
                );
            }
        }
    }

    public function test_the_index_columns_the_search_depends_on_are_the_ones_phase_one_created(): void
    {
        $schema = self::parseSchema();
        $index = $schema['ProductCodeIndex'];

        // The decimal projection is the reason this table exists: CSA is `text` in the
        // EAV tables and cannot be range-queried there.
        self::assertContains('CrossSectionMm2', $index);
        self::assertContains('CrossSectionNominalMm2', $index);
        self::assertContains('CoreCount', $index);
        self::assertContains('StockStatus', $index);
        self::assertContains('ProductVariantId', $index);
    }

    #[Group('azure-sql')]
    public function test_the_search_runs_against_live_azure_sql(): void
    {
        $enabled = getenv('RUN_AZURE_INTEGRATION_TESTS') === 'true'
            || getenv('RUN_AZURE_SQL_INTEGRATION_TESTS') === 'true';

        if (!$enabled) {
            self::markTestSkipped('Set RUN_AZURE_INTEGRATION_TESTS=true to run code search against Azure SQL.');
        }

        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable or not configured for this environment.');
        }

        // The real connection, not raw PDO: OFFSET/FETCH rejects string-bound integers,
        // and it is PdoSqlConnection that binds them as PARAM_INT.
        $repository = new AzureSqlProductCodeSearchRepository(
            new PdoSqlConnection($pdo, new RequestPerformanceMetrics()),
        );

        // Every optional clause at once. A style code no real code carries, so this is
        // a pure "does the statement compile and run" probe.
        $probe = $repository->search(self::everyClauseCriteria());
        self::assertSame(0, $probe->pagination->totalItems);

        // Phase 1's own end-to-end probe, now through the repository: LSZH marine
        // styles, 4 to 25 mm2, 4 cores or more. This one must return rows, which is
        // what proves the page query's SELECT list and OUTER APPLY are valid too.
        $marine = $repository->search(new ProductCodeSearchCriteria(
            cableType: 'MARINE-OFFSHORE',
            coreCountMin: 4,
            coreType: 'C',
            crossSectionMm2Min: 4.0,
            crossSectionMm2Max: 25.0,
            pageSize: 8,
        ));

        self::assertNotSame([], $marine->items, 'The Phase 1 probe returned rows; the repository must too.');
        foreach ($marine->items as $item) {
            self::assertNotSame('', $item->codeValue);
            self::assertNotNull($item->crossSectionMm2);
            self::assertGreaterThanOrEqual(4, $item->coreCount ?? 0);
        }

        self::assertNotSame([], $repository->suggest(substr($marine->items[0]->codeValue, 0, 3), 5));
    }

    /**
     * @return array<string, array{sql: string, params: array<string, mixed>}>
     */
    private static function generatedCalls(): array
    {
        $connection = new FakeSqlConnection();
        // A non-zero count so the page query is built too — search() skips it at zero.
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductCodeSearchRepository($connection);
        $repository->search(self::everyClauseCriteria());
        $repository->suggest('__probe__', 5);

        self::assertCount(3, $connection->calls);

        return [
            'the count query' => $connection->calls[0],
            'the page query' => $connection->calls[1],
            'the suggest query' => $connection->calls[2],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function generatedStatements(): array
    {
        $statements = [];
        foreach (self::generatedCalls() as $label => $call) {
            $statements[$label] = $call['sql'];
        }

        return $statements;
    }

    /**
     * Every optional clause at once, so the schema check sees the whole surface rather
     * than whichever branch a narrower query happened to take. The style code is one no
     * real code carries, which keeps the live run's result set empty.
     */
    private static function everyClauseCriteria(): ProductCodeSearchCriteria
    {
        return new ProductCodeSearchCriteria(
            text: 'marine lszh',
            styleCodes: ['__probe__'],
            cableType: 'MARINE-OFFSHORE',
            voltageClass: 'MV',
            coreCount: 4,
            coreCountMin: 2,
            coreCountMax: 37,
            coreType: 'C',
            crossSectionMm2Min: 1.0,
            crossSectionMm2Max: 630.0,
            conductorCode: 'N',
            jacketColour: 'RE',
            braidArmourScreen: 'T',
            isLszh: true,
            isFireResistant: true,
            isArmoured: false,
            hasScreen: false,
            stockStatuses: ['STOCK', 'MAKE'],
            isCurrent: true,
        );
    }

    private static function tableFor(string $alias): ?string
    {
        if (isset(self::ALIAS_TABLES[$alias])) {
            return self::ALIAS_TABLES[$alias];
        }

        foreach (self::ALIAS_PREFIX_TABLES as $prefix => $table) {
            if (str_starts_with($alias, $prefix)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Column names per [product] table, read from the committed DDL.
     *
     * @return array<string, list<string>>
     */
    private static function parseSchema(): array
    {
        $tables = [];
        foreach (self::SCHEMA_SCRIPTS as $script) {
            $sql = file_get_contents(dirname(__DIR__, 3) . '/' . $script);
            self::assertIsString($sql, 'Unable to read ' . $script);

            $matches = [];
            preg_match_all(
                '/CREATE TABLE \[product\]\.\[(?<table>[A-Za-z0-9_]+)\] \((?<body>.*?)\n\s*\);/s',
                $sql,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $columns = [];
                foreach (preg_split('/\R/', $match['body']) ?: [] as $line) {
                    $line = ltrim($line);
                    if (preg_match('/^\[([A-Za-z0-9_]+)\]\s+[A-Za-z]/', $line, $column) === 1) {
                        $columns[] = $column[1];
                    }
                }
                $tables[$match['table']] = $columns;
            }

            // Columns added by the script's own top-up block, not by its CREATE.
            $alters = [];
            preg_match_all(
                '/ALTER TABLE \[product\]\.\[(?<table>[A-Za-z0-9_]+)\] ADD \[(?<column>[A-Za-z0-9_]+)\]/',
                $sql,
                $alters,
                PREG_SET_ORDER,
            );
            foreach ($alters as $alter) {
                if (isset($tables[$alter['table']]) && !in_array($alter['column'], $tables[$alter['table']], true)) {
                    $tables[$alter['table']][] = $alter['column'];
                }
            }
        }

        self::assertNotEmpty($tables, 'No CREATE TABLE statements parsed from the schema scripts.');

        return $tables;
    }

    /**
     * Every alias.[Column] reference. Bracketed schema/table names such as
     * [product].[Products] do not match, because the alias part must be unbracketed.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function columnReferences(string $sql): array
    {
        $matches = [];
        preg_match_all('/(?<![\]\w])([A-Za-z_][A-Za-z0-9_]*)\.\[([A-Za-z0-9_]+)\]/', $sql, $matches, PREG_SET_ORDER);

        $references = [];
        foreach ($matches as $match) {
            $references[] = [$match[1], $match[2]];
        }

        return $references;
    }
}
