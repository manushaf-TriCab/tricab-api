<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlProductCustomerViewRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class AzureSqlProductCustomerViewRepositoryTest extends TestCase
{
    public function test_variant_matrix_values_query_filters_the_selected_column_definitions(): void
    {
        $publicId = '550e8400-e29b-41d4-a716-446655440000';
        $definitionId = '550e8400-e29b-41d4-a716-446655440001';
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            [
                'PublicId' => $publicId,
                'ProductName' => 'Cable',
                'InternalCode' => 'BHA',
                'ShortDescription' => null,
                'FullDescription' => null,
                'FamilyName' => null,
                'CategoryName' => null,
                'SeriesName' => null,
                'StatusCode' => 'draft',
                'LifecycleStatusCode' => 'concept',
                'IsArchived' => 0,
                'Slug' => 'cable',
            ],
        ]);
        for ($i = 0; $i < 8; ++$i) {
            $connection->queueFetch([]);
        }
        $connection->queueFetch([['FamilyCode' => 'CABLE']]);
        $connection->queueFetch([
            [
                'DefinitionPublicId' => $definitionId,
                'Code' => 'SIZE',
                'Name' => 'Size',
                'GroupName' => 'Dimensions',
                'Unit' => 'mm',
                'DataTypeCode' => 'text',
                'DisplayOrder' => 1,
                'ValidationRulesJson' => null,
                'GroupDisplayOrder' => 1,
            ],
        ]);
        $connection->queueFetch([]);
        $connection->queueFetch([
            [
                'StyleCode' => 'BHA',
                'ParentStyleCode' => 'BH',
            ],
        ]);

        $repository = new AzureSqlProductCustomerViewRepository(
            $connection,
            $this->createStub(Product3DModelRepository::class),
            false,
        );
        $detail = $repository->findByPublicId(ProductPublicId::fromString($publicId));
        self::assertNotNull($detail);
        self::assertSame('BHA', $detail->styleCode);
        self::assertSame('BH', $detail->parentStyleCode);

        $valueSql = null;
        foreach ($connection->calls as $call) {
            if (str_contains($call['sql'], 'cv.matrix_values')) {
                $valueSql = $call['sql'];
                break;
            }
        }
        self::assertNotNull($valueSql);
        self::assertStringContainsString('[PublicId] IN', $valueSql);
        self::assertMatchesRegularExpression(
            '/FROM\s+\[product\]\.\[SpecificationDefinitions\]/i',
            $valueSql,
        );
        self::assertStringNotContainsString('ORDER BY', $valueSql);
        $valueCall = null;
        foreach ($connection->calls as $call) {
            if (str_contains($call['sql'], 'cv.matrix_values')) {
                $valueCall = $call;
                break;
            }
        }
        self::assertNotNull($valueCall);
        self::assertSame($definitionId, $valueCall['params'][':def0']);
        $columnsSql = '';
        foreach ($connection->calls as $call) {
            if (str_contains($call['sql'], 'cv.matrix_columns')) {
                $columnsSql = $call['sql'];
            }
        }
        self::assertStringContainsString("NOT LIKE N'CODE[_]%'", $columnsSql, 'CODE_* definitions are never matrix columns');
    }

    public function test_style_family_products_come_from_the_style_hierarchy_and_are_region_filtered(): void
    {
        $publicId = '550e8400-e29b-41d4-a716-446655440000';
        $connection = new FakeSqlConnection();
        $connection->queueFetch([[
            'PublicId' => $publicId, 'ProductName' => 'Shipboard Power & Control', 'InternalCode' => 'BVA', 'ShortDescription' => null,
            'FullDescription' => null, 'FamilyName' => null, 'CategoryName' => null, 'SeriesName' => null, 'StatusCode' => 'published',
            'LifecycleStatusCode' => 'active', 'IsArchived' => 0, 'Slug' => 'bva',
        ]]);
        for ($i = 0; $i < 8; ++$i) {
            $connection->queueFetch([]);
        }
        $connection->queueFetch([['FamilyCode' => 'CABLE']]);
        $connection->queueFetch([]);
        $connection->queueFetch([['StyleCode' => 'BVA', 'ParentStyleCode' => 'BV']]);
        $connection->queueFetch([
            ['PublicId' => $publicId, 'InternalCode' => 'BVA', 'StyleCode' => 'BVA', 'Slug' => 'bva',
                'JacketOptionCodes' => 'JACKET-OPTION-A,JACKET-OPTION-C,JACKET-OPTION-M'],
            ['PublicId' => 'B5FCDED9-F691-F111-B337-000D3AE094E4', 'InternalCode' => 'BVC', 'StyleCode' => 'BVC', 'Slug' => null,
                'JacketOptionCodes' => 'JACKET-OPTION-C'],
            ['PublicId' => '550e8400-e29b-41d4-a716-446655440099', 'InternalCode' => 'BVP', 'StyleCode' => 'BVP', 'Slug' => 'bvp',
                'JacketOptionCodes' => null],
        ]);

        $repository = new AzureSqlProductCustomerViewRepository($connection, $this->createStub(Product3DModelRepository::class), false);
        $detail = $repository->findByPublicId(ProductPublicId::fromString($publicId), 'au');
        self::assertNotNull($detail);

        self::assertSame(['BVA', 'BVC', 'BVP'], array_map(static fn ($p): string => $p->productCode, $detail->styleFamilyProducts));
        self::assertSame(['JACKET-OPTION-A', 'JACKET-OPTION-C', 'JACKET-OPTION-M'], $detail->styleFamilyProducts[0]->jacketOptionCodes);
        self::assertNull($detail->styleFamilyProducts[1]->slug);
        self::assertSame('B5FCDED9-F691-F111-B337-000D3AE094E4', $detail->styleFamilyProducts[1]->publicId);
        self::assertSame([], $detail->styleFamilyProducts[2]->jacketOptionCodes);

        $familyCall = null;
        foreach ($connection->calls as $call) {
            if (str_contains($call['sql'], 'cv.style_family')) {
                $familyCall = $call;
            }
        }
        self::assertNotNull($familyCall);
        self::assertSame('AU', $familyCall['params'][':regionCode']);
        self::assertStringContainsString('[ParentStyleId]', $familyCall['sql']);
        self::assertStringContainsString("[StatusCode] = N'published'", $familyCall['sql']);
        self::assertStringContainsString('[ProductRegions]', $familyCall['sql']);
        self::assertStringNotContainsString('LIKE :', $familyCall['sql'], 'family membership never comes from code matching');

        $serialized = \TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer::customerViewDetail($detail);
        self::assertIsArray($serialized['styleFamilyProducts']);
        self::assertSame(
            ['publicId' => 'B5FCDED9-F691-F111-B337-000D3AE094E4', 'productCode' => 'BVC', 'styleCode' => 'BVC', 'slug' => null,
                'jacketOptionCodes' => ['JACKET-OPTION-C']],
            $serialized['styleFamilyProducts'][1],
        );
    }

    public function test_products_without_a_style_skip_the_family_query(): void
    {
        $publicId = '550e8400-e29b-41d4-a716-446655440000';
        $connection = new FakeSqlConnection();
        $connection->queueFetch([[
            'PublicId' => $publicId, 'ProductName' => 'Tie', 'InternalCode' => 'H01', 'ShortDescription' => null,
            'FullDescription' => null, 'FamilyName' => null, 'CategoryName' => null, 'SeriesName' => null, 'StatusCode' => 'published',
            'LifecycleStatusCode' => 'active', 'IsArchived' => 0, 'Slug' => 'h01',
        ]]);

        $repository = new AzureSqlProductCustomerViewRepository($connection, $this->createStub(Product3DModelRepository::class), false);
        $detail = $repository->findByPublicId(ProductPublicId::fromString($publicId), 'AU');
        self::assertNotNull($detail);
        self::assertSame([], $detail->styleFamilyProducts);
        foreach ($connection->calls as $call) {
            self::assertStringNotContainsString('cv.style_family', $call['sql']);
        }
    }

    public function test_variant_matrix_preserves_cells_nulls_decimals_and_options(): void
    {
        $publicId = '550e8400-e29b-41d4-a716-446655440000';
        $sizeId = '550e8400-e29b-41d4-a716-446655440001';
        $weightId = '550e8400-e29b-41d4-a716-446655440002';
        $currentId = '550e8400-e29b-41d4-a716-446655440003';
        $variantA = '550e8400-e29b-41d4-a716-446655440010';
        $variantB = '550e8400-e29b-41d4-a716-446655440011';

        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            [
                'PublicId' => $publicId,
                'ProductName' => 'Cable',
                'InternalCode' => 'BHA',
                'ShortDescription' => null,
                'FullDescription' => null,
                'FamilyName' => null,
                'CategoryName' => null,
                'SeriesName' => null,
                'StatusCode' => 'draft',
                'LifecycleStatusCode' => 'concept',
                'IsArchived' => 0,
                'Slug' => 'cable',
            ],
        ]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);
        $connection->queueFetch([
            $this->variantRow($variantA, 'Variant A', 1),
            $this->variantRow($variantB, 'Variant B', 2),
        ]);
        for ($i = 0; $i < 5; ++$i) {
            $connection->queueFetch([]);
        }
        $connection->queueFetch([['FamilyCode' => 'CABLE']]);
        $connection->queueFetch([
            $this->columnRow($sizeId, 'SIZE', 'Size', 'text'),
            $this->columnRow($weightId, 'APPROX-WEIGHT', 'Approx Weight', 'decimal'),
            $this->columnRow($currentId, 'CURRENT', 'Current', 'single-select'),
        ]);
        $connection->queueFetch([
            $this->valueRow($variantA, $sizeId, valueText: '16mm'),
            $this->valueRow($variantA, $weightId, valueDecimal: '12.50'),
            $this->valueRow($variantA, $currentId, optionLabel: 'Red'),
            $this->valueRow($variantB, $sizeId, valueText: null),
            $this->valueRow($variantB, $weightId, valueDecimal: '0'),
        ]);
        $connection->queueFetch([
            [
                'StyleCode' => 'BHA',
                'ParentStyleCode' => 'BH',
            ],
        ]);

        $repository = new AzureSqlProductCustomerViewRepository(
            $connection,
            $this->createStub(Product3DModelRepository::class),
            false,
        );
        $detail = $repository->findByPublicId(ProductPublicId::fromString($publicId));
        self::assertNotNull($detail);

        self::assertCount(2, $detail->variants);
        self::assertSame($variantA, $detail->variants[0]->publicId);
        self::assertSame($variantB, $detail->variants[1]->publicId);
        self::assertCount(2, $detail->variantMatrix->rows);
        self::assertSame(
            [$variantA, $variantB],
            array_map(static fn ($row): string => $row->publicId, $detail->variantMatrix->rows),
        );

        $columns = $detail->variantMatrix->columns;
        self::assertSame(['SIZE', 'CURRENT', 'APPROX-WEIGHT'], array_map(static fn ($c): string => $c->code, $columns));
        self::assertSame([1, 6, 7], array_map(static fn ($c): int => $c->displayOrder, $columns));

        $rowA = $detail->variantMatrix->rows[0];
        $rowB = $detail->variantMatrix->rows[1];
        self::assertSame('16mm', $rowA->cells[$sizeId]);
        self::assertSame('12.5', $rowA->cells[$weightId]);
        self::assertSame('Red', $rowA->cells[$currentId]);
        self::assertNull($rowB->cells[$sizeId]);
        self::assertSame('0', $rowB->cells[$weightId]);
        self::assertNull($rowB->cells[$currentId]);
        self::assertCount(3, $rowA->cells);
        self::assertCount(3, $rowB->cells);
    }

    public function test_cable_matrix_keeps_the_seven_technical_columns_in_catalogue_order_even_without_values(): void
    {
        $ids = [];
        $rows = [];
        // returned in a scrambled order, with an extra variant definition that has values: cables still show exactly the 7
        foreach (['APPROX-WEIGHT', 'SIZE', 'CURRENT', 'NOM-CABLE-OD', 'MIN-BEND-RADIUS', 'NOM-INS-OD', 'NOM-COND-OD', 'OVERALL-LENGTH'] as $i => $code) {
            $ids[$code] = sprintf('550e8400-e29b-41d4-a716-4466554401%02d', $i);
            $rows[] = $this->columnRow($ids[$code], $code, $code, 'decimal') + ['HasValues' => $code === 'OVERALL-LENGTH' ? 1 : 0];
        }
        $detail = $this->detailWithMatrix('CABLE', $rows, []);
        self::assertSame(['SIZE', 'MIN-BEND-RADIUS', 'NOM-COND-OD', 'NOM-INS-OD', 'NOM-CABLE-OD', 'CURRENT', 'APPROX-WEIGHT'],
            array_map(static fn ($c): string => $c->code, $detail->variantMatrix->columns));
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn ($c): int => $c->displayOrder, $detail->variantMatrix->columns));
        self::assertSame('grouped', $detail->variantMatrix->layout, 'cables keep the size-grouped table');
    }

    public function test_accessory_matrix_derives_columns_from_its_values_in_group_then_definition_order(): void
    {
        // the A09 shape: 7 accessory dimensions over two groups, no cable technical dimension; the 7 cable definitions exist without values
        $defs = [
            ['OVERALL-LENGTH', 20, 300], ['WIDTH', 20, 301], ['BARREL-LENGTH', 20, 305], ['BARREL-OD', 20, 309], ['PIN-DIAMETER', 20, 312],
            ['MIN-COND-CSA', 30, 314], ['MAX-COND-CSA', 30, 315],
        ];
        $rows = [];
        $ids = [];
        foreach (array_reverse($defs) as $i => [$code, $group, $order]) {
            $ids[$code] = sprintf('550e8400-e29b-41d4-a716-4466554402%02d', $i);
            $rows[] = ['DefinitionPublicId' => $ids[$code], 'Code' => $code, 'Name' => $code, 'GroupName' => 'G' . $group, 'Unit' => 'mm', 'DataTypeCode' => 'decimal',
                'DisplayOrder' => $order, 'ValidationRulesJson' => null, 'GroupDisplayOrder' => $group, 'HasValues' => 1];
        }
        foreach (['SIZE', 'CURRENT'] as $j => $code) {
            $rows[] = $this->columnRow(sprintf('550e8400-e29b-41d4-a716-4466554403%02d', $j), $code, $code, 'decimal') + ['HasValues' => 0];
        }
        $variant = '550e8400-e29b-41d4-a716-446655440010';
        $values = [];
        foreach ($ids as $code => $id) {
            $values[] = $this->valueRow($variant, $id, valueDecimal: '12.000000');
        }
        $detail = $this->detailWithMatrix('ACCESSORIES', $rows, $values, [$this->variantRow($variant, 'Pin 12mm', 1)],
            [['VariantPublicId' => strtoupper($variant), 'PackagingDescription' => '100 per pack', 'ColourLabel' => 'Red']]);
        self::assertSame('flat', $detail->variantMatrix->layout);
        self::assertSame('ACCESSORIES', $detail->variantMatrix->familyCode);
        self::assertSame(['Red', '100 per pack'], [$detail->variantMatrix->rows[0]->colour, $detail->variantMatrix->rows[0]->packSize]);
        self::assertNotContains('CODE_JACKET_COLOUR', array_map(static fn ($c): string => $c->code, $detail->variantMatrix->columns), 'colour is a presentation column, never a technical one');
        self::assertSame(['OVERALL-LENGTH', 'WIDTH', 'BARREL-LENGTH', 'BARREL-OD', 'PIN-DIAMETER', 'MIN-COND-CSA', 'MAX-COND-CSA'],
            array_map(static fn ($c): string => $c->code, $detail->variantMatrix->columns));
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn ($c): int => $c->displayOrder, $detail->variantMatrix->columns));
        self::assertCount(7, $detail->variantMatrix->rows[0]->cells);
        self::assertSame(['12'], array_values(array_unique($detail->variantMatrix->rows[0]->cells)));
    }

    /**
     * @param list<array<string, mixed>> $columnRows
     * @param list<array<string, mixed>> $valueRows
     * @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>> $presentationRows
     */
    private function detailWithMatrix(string $family, array $columnRows, array $valueRows, array $variants = [], array $presentationRows = []): \TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail
    {
        $publicId = '550e8400-e29b-41d4-a716-446655440000';
        $connection = new FakeSqlConnection();
        $connection->queueFetch([[
            'PublicId' => $publicId, 'ProductName' => 'P', 'InternalCode' => 'X01', 'ShortDescription' => null, 'FullDescription' => null,
            'FamilyName' => null, 'CategoryName' => null, 'SeriesName' => null, 'StatusCode' => 'draft', 'LifecycleStatusCode' => 'concept',
            'IsArchived' => 0, 'Slug' => 'p',
        ]]);
        $connection->queueFetch([]);
        $connection->queueFetch([]);
        $connection->queueFetch($variants);
        for ($i = 0; $i < 5; ++$i) {
            $connection->queueFetch([]);
        }
        $connection->queueFetch([['FamilyCode' => $family]]);
        $connection->queueFetch($columnRows);
        $connection->queueFetch($valueRows);
        if ($family !== 'CABLE' && $variants !== []) {
            $connection->queueFetch($presentationRows);
        }
        $connection->queueFetch([]);
        $repository = new AzureSqlProductCustomerViewRepository($connection, $this->createStub(Product3DModelRepository::class), false);
        $detail = $repository->findByPublicId(ProductPublicId::fromString($publicId));
        self::assertNotNull($detail);

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    private function variantRow(string $publicId, string $name, int $order): array
    {
        return [
            'PublicId' => $publicId,
            'VariantName' => $name,
            'Sku' => null,
            'VariantCode' => null,
            'StatusCode' => 'draft',
            'DisplayOrder' => $order,
            'IsActive' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnRow(string $publicId, string $code, string $name, string $dataType): array
    {
        return [
            'DefinitionPublicId' => $publicId,
            'Code' => $code,
            'Name' => $name,
            'GroupName' => 'Dimensions',
            'Unit' => 'mm',
            'DataTypeCode' => $dataType,
            'DisplayOrder' => 1,
            'ValidationRulesJson' => null,
            'GroupDisplayOrder' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valueRow(
        string $variantPublicId,
        string $definitionPublicId,
        ?string $valueText = null,
        ?string $valueDecimal = null,
        ?string $optionLabel = null,
    ): array {
        return [
            'VariantPublicId' => $variantPublicId,
            'DefinitionPublicId' => $definitionPublicId,
            'ValueText' => $valueText,
            'ValueLongText' => null,
            'ValueInteger' => null,
            'ValueDecimal' => $valueDecimal,
            'ValueBoolean' => null,
            'ValueMinDecimal' => null,
            'ValueMaxDecimal' => null,
            'OptionLabel' => $optionLabel,
        ];
    }
}
