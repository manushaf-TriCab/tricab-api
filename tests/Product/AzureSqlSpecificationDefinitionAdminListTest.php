<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlSpecificationDefinitionAdmin;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;

final class AzureSqlSpecificationDefinitionAdminListTest extends TestCase
{
    public function test_list_definitions_uses_batched_usage_and_options_queries(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            $this->definitionRow('def-a', 'VOLTAGE', 'Voltage'),
            $this->definitionRow('def-b', 'CURRENT', 'Current'),
        ]);
        $connection->queueFetch([
            ['PublicId' => 'def-a', 'Cnt' => 4],
        ]);
        $connection->queueFetch([
            ['PublicId' => 'def-b', 'Cnt' => 9],
        ]);
        $connection->queueFetch([
            $this->optionRow('opt-1', 'def-a', '230V'),
            $this->optionRow('opt-2', 'def-b', '16A'),
        ]);

        $admin = new AzureSqlSpecificationDefinitionAdmin($connection);
        $items = $admin->listDefinitions(['activeOnly' => true]);

        self::assertCount(2, $items);
        self::assertCount(4, $connection->calls);
        self::assertStringContainsString('SpecificationDefinitions', $connection->calls[0]['sql']);
        self::assertStringContainsString('ProductSpecificationValues', $connection->calls[1]['sql']);
        self::assertStringContainsString('GROUP BY', $connection->calls[1]['sql']);
        self::assertStringContainsString('ProductVariantSpecificationValues', $connection->calls[2]['sql']);
        self::assertStringContainsString('SpecificationOptions', $connection->calls[3]['sql']);
        self::assertStringContainsString('IN (', $connection->calls[3]['sql']);

        self::assertSame('VOLTAGE', $items[0]->code);
        self::assertSame(4, $items[0]->productValueCount);
        self::assertSame(0, $items[0]->variantValueCount);
        self::assertCount(1, $items[0]->options);
        self::assertSame('230V', $items[0]->options[0]->name);
        self::assertSame('CURRENT', $items[1]->code);
        self::assertSame(0, $items[1]->productValueCount);
        self::assertSame(9, $items[1]->variantValueCount);
        self::assertSame('16A', $items[1]->options[0]->name);
        self::assertNull($items[0]->unitPublicId);
        self::assertNotSame('', $items[0]->rowVersion);
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionRow(string $publicId, string $code, string $name): array
    {
        return [
            'PublicId' => $publicId,
            'Code' => $code,
            'Name' => $name,
            'Description' => null,
            'GroupPublicId' => null,
            'GroupCode' => null,
            'GroupName' => null,
            'DataTypeCode' => 'select',
            'ScopeCode' => 'product',
            'Unit' => 'V',
            'DisplayOrder' => 10,
            'IsRequired' => 0,
            'IsFilterable' => 1,
            'IsSearchable' => 0,
            'IsComparable' => 0,
            'MinimumValue' => null,
            'MaximumValue' => null,
            'DecimalPrecision' => null,
            'ValidationRulesJson' => null,
            'IsActive' => 1,
            'IsDeleted' => 0,
            'RowVersion' => hex2bin('0000000000000001'),
            'CreatedAtUtc' => '2026-01-01T00:00:00',
            'UpdatedAtUtc' => '2026-01-02T00:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionRow(string $publicId, string $definitionPublicId, string $name): array
    {
        return [
            'PublicId' => $publicId,
            'DefinitionPublicId' => $definitionPublicId,
            'Code' => strtoupper($name),
            'Name' => $name,
            'Value' => $name,
            'DisplayOrder' => 1,
            'IsActive' => 1,
            'IsDeleted' => 0,
            'RowVersion' => hex2bin('0000000000000002'),
            'CreatedAtUtc' => '2026-01-01T00:00:00',
            'UpdatedAtUtc' => '2026-01-02T00:00:00',
        ];
    }
}
