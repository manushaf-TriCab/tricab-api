<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CableCalculator\AzureSqlCableCalculatorProductResolver;

/**
 * The resolver's SQL selected [Products].[Slug] and [Products].[Name], neither of which
 * exists. Every live call raised "Invalid column name" and the resolver's own catch
 * flattened it to RESOLVER_ERROR, so nothing surfaced — 0 of 17,995 calculated codes
 * resolved and the cause was invisible.
 *
 * These tests close that gap from both ends: the schema test checks every column the SQL
 * names against the committed DDL and runs everywhere with no database, and the live test
 * executes the statement against Azure SQL when one is configured.
 */
final class CableCalculatorProductResolverSqlSchemaTest extends TestCase
{
    private const SCHEMA_SCRIPT = 'database/scripts/development/create-product-schema.sql';

    /**
     * Table behind each alias used in AzureSqlCableCalculatorProductResolver::RESOLVE_SQL.
     *
     * @var array<string, string>
     */
    private const ALIAS_TABLES = [
        'pc' => 'ProductCodes',
        'pcv' => 'ProductVariants',
        'v' => 'ProductVariants',
        'p' => 'Products',
        'ps' => 'ProductStatuses',
        'slug' => 'ProductSlugs',
    ];

    /**
     * Columns projected by the RESOLVE_SQL common table expression, referenced as m.[...].
     *
     * @var list<string>
     */
    private const CTE_COLUMNS = ['ProductId', 'ProductVariantId', 'MatchRank'];

    public function test_every_column_the_resolver_selects_exists_in_the_schema(): void
    {
        $schema = self::parseSchema();
        $references = self::columnReferences(AzureSqlCableCalculatorProductResolver::RESOLVE_SQL);

        self::assertNotEmpty($references, 'No aliased column references found — the parser is broken, not the SQL.');

        foreach ($references as [$alias, $column]) {
            if ($alias === 'm') {
                self::assertContains(
                    $column,
                    self::CTE_COLUMNS,
                    "RESOLVE_SQL selects m.[{$column}], which the CodeMatch CTE does not project.",
                );

                continue;
            }

            self::assertArrayHasKey(
                $alias,
                self::ALIAS_TABLES,
                "RESOLVE_SQL uses alias '{$alias}'. Add it to ALIAS_TABLES so its columns are checked.",
            );

            $table = self::ALIAS_TABLES[$alias];
            self::assertArrayHasKey($table, $schema, "[product].[{$table}] is not in " . self::SCHEMA_SCRIPT);
            self::assertContains(
                $column,
                $schema[$table],
                "RESOLVE_SQL selects {$alias}.[{$column}] but [product].[{$table}] has no such column.",
            );
        }
    }

    public function test_the_columns_that_were_actually_wrong_are_named_correctly(): void
    {
        $schema = self::parseSchema();

        // The regression itself: these two never existed on [Products].
        self::assertNotContains('Slug', $schema['Products']);
        self::assertNotContains('Name', $schema['Products']);

        // And where they really live.
        self::assertContains('ProductName', $schema['Products']);
        self::assertContains('Slug', $schema['ProductSlugs']);

        $sql = AzureSqlCableCalculatorProductResolver::RESOLVE_SQL;
        self::assertStringContainsString('p.[ProductName] AS [DisplayName]', $sql);
        self::assertStringContainsString('[product].[ProductSlugs] slug', $sql);
    }

    public function test_the_resolver_matches_variant_codes_as_well_as_curated_codes(): void
    {
        $sql = AzureSqlCableCalculatorProductResolver::RESOLVE_SQL;

        // ProductCodes is empty by design in catalog-load output, so a resolver that reads
        // only that table can never resolve anything.
        self::assertStringContainsString('v.[VariantCode] = :codeVariant', $sql);
        self::assertStringContainsString('pc.[CodeValue] = :codeCurated', $sql);
    }

    #[Group('azure-sql')]
    public function test_the_statement_executes_against_live_azure_sql(): void
    {
        $enabled = getenv('RUN_AZURE_INTEGRATION_TESTS') === 'true'
            || getenv('RUN_AZURE_SQL_INTEGRATION_TESTS') === 'true';

        if (!$enabled) {
            self::markTestSkipped('Set RUN_AZURE_INTEGRATION_TESTS=true to execute resolver SQL against Azure SQL.');
        }

        $pdo = LiveAzureSqlSupport::connect();
        if ($pdo === null) {
            self::markTestSkipped('Azure SQL is not reachable or not configured for this environment.');
        }

        // A bad column name fails here at prepare/execute rather than being swallowed.
        $stmt = $pdo->prepare(AzureSqlCableCalculatorProductResolver::RESOLVE_SQL);
        $probe = '__tricab_resolver_schema_probe__';
        $stmt->execute(['codeCurated' => $probe, 'codeVariant' => $probe]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame([], $rows, 'The probe code must not exist in the catalogue.');

        // Every column the resolver reads must be present in the result metadata even when
        // no row matched, which is what proves the SELECT list is valid.
        $expected = ['ProductPublicId', 'Slug', 'DisplayName', 'StatusCode', 'VariantPublicId', 'VariantCode'];
        $actual = [];
        for ($i = 0, $columns = $stmt->columnCount(); $i < $columns; $i++) {
            $meta = $stmt->getColumnMeta($i);
            self::assertIsArray($meta, "No column metadata at position {$i}.");
            $actual[] = $meta['name'];
        }

        self::assertSame($expected, $actual);
    }

    /**
     * Column names per [product] table, read from the committed DDL.
     *
     * @return array<string, list<string>>
     */
    private static function parseSchema(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::SCHEMA_SCRIPT;
        $sql = file_get_contents($path);
        self::assertIsString($sql, 'Unable to read ' . self::SCHEMA_SCRIPT);

        $tables = [];
        $matches = [];
        preg_match_all(
            '/CREATE TABLE \[product\]\.\[(?<table>[A-Za-z0-9_]+)\] \((?<body>.*?)\n\);/s',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $columns = [];
            foreach (preg_split('/\R/', $match['body']) ?: [] as $line) {
                $line = ltrim($line);
                // Column definitions open with a bracketed name; constraints open with CONSTRAINT.
                if (preg_match('/^\[([A-Za-z0-9_]+)\]\s+[A-Za-z]/', $line, $column) === 1) {
                    $columns[] = $column[1];
                }
            }
            $tables[$match['table']] = $columns;
        }

        self::assertNotEmpty($tables, 'No CREATE TABLE statements parsed from ' . self::SCHEMA_SCRIPT);

        return $tables;
    }

    /**
     * Every alias.[Column] reference in a statement. Bracketed schema/table names such as
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
