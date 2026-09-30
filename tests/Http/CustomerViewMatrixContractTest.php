<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Product\Persistence\AzureSqlProductCustomerViewRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * The variantMatrix JSON contract consumed by the web CustomerProductDatasheet, produced by the production
 * repository + serializer (the same path GetProductCustomerViewAction serves over HTTP).
 *
 * Regression guard: an ACCESSORIES product whose variants carry their own dimensions must never be served the
 * fixed cable Technical Dimensions columns (which it has no values for), and cables must keep exactly those columns.
 */
final class CustomerViewMatrixContractTest extends TestCase
{
    private const CABLE_ONLY_CODES = ['SIZE', 'MIN-BEND-RADIUS', 'NOM-COND-OD', 'NOM-INS-OD', 'NOM-CABLE-OD', 'CURRENT', 'APPROX-WEIGHT'];

    public function test_a09_style_accessory_serves_its_seven_dimensions_as_a_flat_matrix(): void
    {
        $codes = ['MIN-COND-CSA', 'MAX-COND-CSA', 'PIN-DIAMETER', 'OVERALL-LENGTH', 'BARREL-LENGTH', 'BARREL-OD', 'WIDTH'];
        $matrix = $this->accessoryMatrix($codes, [
            ['A09-AMXX/12B1.5RE', 'Red', '100 per pack'],
            ['A09-AMXX/12B2.5BL', 'Blue', '100 per pack'],
            ['A09-AMXX/14B6YE', 'Yellow', '100 per pack'],
        ]);

        self::assertSame('flat', $matrix['layout']);
        self::assertSame('ACCESSORIES', $matrix['familyCode']);
        self::assertSame($codes, array_column($matrix['columns'], 'code'));
        self::assertCount(3, $matrix['rows']);
        self::assertSame(21, $this->populated($matrix));
        self::assertSame(['Red', 'Blue', 'Yellow'], array_column($matrix['rows'], 'colour'));
        self::assertSame(['100 per pack'], array_values(array_unique(array_column($matrix['rows'], 'packSize'))));
        self::assertSame([], array_values(array_intersect(self::CABLE_ONLY_CODES, array_column($matrix['columns'], 'code'))),
            'an accessory must not fall back to the cable Technical Dimensions columns');
    }

    public function test_f12_style_accessory_serves_its_six_dimensions_as_a_flat_matrix(): void
    {
        $codes = ['DEPTH', 'HEIGHT', 'INNER-DIAMETER', 'OUTER-DIAMETER', 'OVERALL-LENGTH', 'WIDTH'];
        $matrix = $this->accessoryMatrix($codes, [
            ['F12-AHXX/XD20GY', 'Grey', '20 per pack'],
            ['F12-AHXX/XD25GY', 'Grey', '20 per pack'],
        ]);

        self::assertSame('flat', $matrix['layout']);
        self::assertSame($codes, array_column($matrix['columns'], 'code'));
        self::assertCount(2, $matrix['rows']);
        self::assertSame(12, $this->populated($matrix));
        self::assertSame(['Grey', 'Grey'], array_column($matrix['rows'], 'colour'));
        self::assertSame(['20 per pack', '20 per pack'], array_column($matrix['rows'], 'packSize'));
        self::assertSame([], array_values(array_intersect(self::CABLE_ONLY_CODES, array_column($matrix['columns'], 'code'))));
    }

    public function test_cable_keeps_the_grouped_matrix_and_its_seven_fixed_columns(): void
    {
        $rows = [];
        foreach (array_merge(self::CABLE_ONLY_CODES, ['WIDTH']) as $i => $code) {
            $rows[] = $this->columnRow($this->definitionId($i), $code, 1, $i + 1, $code === 'WIDTH');
        }
        $variant = $this->variantId(0);
        $values = [$this->valueRow($variant, $this->definitionId(0), '16.000000')];
        $matrix = $this->serializedMatrix('CABLE', $rows, $values, [$this->variantRow($variant, 'R4 16mm', 1)], null);

        self::assertSame('grouped', $matrix['layout']);
        self::assertSame(self::CABLE_ONLY_CODES, array_column($matrix['columns'], 'code'));
        self::assertNull($matrix['rows'][0]['colour']);
        self::assertNull($matrix['rows'][0]['packSize']);
    }

    /**
     * @param list<string> $codes
     * @param list<array{0: string, 1: string, 2: string}> $variants
     *
     * @return array<string, mixed>
     */
    private function accessoryMatrix(array $codes, array $variants): array
    {
        $columnRows = [];
        foreach ($codes as $i => $code) {
            $columnRows[] = $this->columnRow($this->definitionId($i), $code, 20, 300 + $i, true);
        }
        // the cable definitions exist in the catalogue but this product has no values for them
        foreach (self::CABLE_ONLY_CODES as $j => $code) {
            $columnRows[] = $this->columnRow($this->definitionId(50 + $j), $code, 1, $j + 1, false);
        }
        $variantRows = [];
        $valueRows = [];
        $presentation = [];
        foreach ($variants as $v => [$name, $colour, $pack]) {
            $id = $this->variantId($v);
            $variantRows[] = $this->variantRow($id, $name, $v + 1);
            $presentation[] = ['VariantPublicId' => strtoupper($id), 'PackagingDescription' => $pack, 'ColourLabel' => $colour];
            foreach ($codes as $i => $_) {
                $valueRows[] = $this->valueRow($id, $this->definitionId($i), sprintf('%d.000000', 10 + $v + $i));
            }
        }

        return $this->serializedMatrix('ACCESSORIES', $columnRows, $valueRows, $variantRows, $presentation);
    }

    /**
     * @param array<string, mixed> $matrix
     */
    private function populated(array $matrix): int
    {
        $n = 0;
        foreach ($matrix['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                $n += $cell === null ? 0 : 1;
            }
        }

        return $n;
    }

    /**
     * @param list<array<string, mixed>> $columnRows
     * @param list<array<string, mixed>> $valueRows
     * @param list<array<string, mixed>> $variants
     * @param list<array<string, mixed>>|null $presentationRows
     *
     * @return array<string, mixed>
     */
    private function serializedMatrix(string $family, array $columnRows, array $valueRows, array $variants, ?array $presentationRows): array
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
        if ($presentationRows !== null) {
            $connection->queueFetch($presentationRows);
        }
        $connection->queueFetch([]);
        $repository = new AzureSqlProductCustomerViewRepository($connection, $this->createStub(Product3DModelRepository::class), false);
        $detail = $repository->findByPublicId(ProductPublicId::fromString($publicId));
        self::assertNotNull($detail);

        // round-trip through JSON: this is the payload the browser receives
        $payload = json_decode((string) json_encode(ProductManagementDtoSerializer::customerViewDetail($detail)), true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['variantMatrix']);

        return $payload['variantMatrix'];
    }

    private function definitionId(int $i): string
    {
        return sprintf('550e8400-e29b-41d4-a716-4466554405%02d', $i);
    }

    private function variantId(int $i): string
    {
        return sprintf('550e8400-e29b-41d4-a716-4466554406%02d', $i);
    }

    /**
     * @return array<string, mixed>
     */
    private function columnRow(string $publicId, string $code, int $group, int $order, bool $hasValues): array
    {
        return ['DefinitionPublicId' => $publicId, 'Code' => $code, 'Name' => $code, 'GroupName' => 'G' . $group, 'Unit' => 'mm',
            'DataTypeCode' => 'decimal', 'DisplayOrder' => $order, 'ValidationRulesJson' => null, 'GroupDisplayOrder' => $group,
            'HasValues' => $hasValues ? 1 : 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantRow(string $publicId, string $name, int $order): array
    {
        return ['PublicId' => $publicId, 'VariantName' => $name, 'Sku' => null, 'VariantCode' => $name, 'StatusCode' => 'draft',
            'DisplayOrder' => $order, 'IsActive' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function valueRow(string $variantPublicId, string $definitionPublicId, string $valueDecimal): array
    {
        return ['VariantPublicId' => $variantPublicId, 'DefinitionPublicId' => $definitionPublicId, 'ValueText' => null,
            'ValueLongText' => null, 'ValueInteger' => null, 'ValueDecimal' => $valueDecimal, 'ValueBoolean' => null,
            'ValueMinDecimal' => null, 'ValueMaxDecimal' => null, 'OptionLabel' => null];
    }
}
