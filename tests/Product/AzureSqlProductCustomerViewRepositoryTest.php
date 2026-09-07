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
    public function test_variant_matrix_values_query_filters_technical_dimension_codes(): void
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
            if (str_contains($call['sql'], 'ProductVariantSpecificationValues')) {
                $valueSql = $call['sql'];
                break;
            }
        }
        self::assertNotNull($valueSql);
        self::assertStringContainsString('DefinitionCode] IN', $valueSql);
        self::assertMatchesRegularExpression(
            '/FROM\s+\[product\]\.\[SpecificationDefinitions\]/i',
            $valueSql,
        );
        self::assertStringNotContainsString('ORDER BY', $valueSql);
        $valueCall = null;
        foreach ($connection->calls as $call) {
            if (str_contains($call['sql'], 'ProductVariantSpecificationValues')) {
                $valueCall = $call;
                break;
            }
        }
        self::assertNotNull($valueCall);
        self::assertArrayHasKey(':code0', $valueCall['params']);
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
