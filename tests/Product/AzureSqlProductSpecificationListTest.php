<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlProductSpecificationRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

final class AzureSqlProductSpecificationListTest extends TestCase
{
    private const PRODUCT_ID = '9970e6f0-f091-f111-b337-000d3ae094e4';

    private const VARIANT_ID = 'aa70e6f0-f091-f111-b337-000d3ae094e4';

    public function test_list_product_values_batches_junction_options_and_preserves_shape(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            $this->valueRow(11, 'def-text', valueText: 'Red'),
            $this->valueRow(12, 'def-empty'),
            $this->valueRow(13, 'def-select', optionPublicId: 'opt-single'),
            $this->valueRow(14, 'def-multi'),
            $this->valueRow(15, 'def-unit', valueDecimal: '12.50'),
        ]);
        $connection->queueFetch([
            ['ValueId' => 13, 'OptionPublicId' => 'opt-single'],
            ['ValueId' => 14, 'OptionPublicId' => 'opt-a'],
            ['ValueId' => 14, 'OptionPublicId' => 'opt-b'],
        ]);

        $repository = new AzureSqlProductSpecificationRepository($connection);
        $values = $repository->listProductValues(ProductPublicId::fromString(self::PRODUCT_ID));

        self::assertCount(2, $connection->calls);
        self::assertStringContainsString('ProductSpecificationValues', $connection->calls[0]['sql']);
        self::assertStringContainsString('ProductSpecificationOptionValues', $connection->calls[1]['sql']);
        self::assertStringContainsString('IN (', $connection->calls[1]['sql']);
        self::assertSame(11, $connection->calls[1]['params'][':vid0']);
        self::assertSame(15, $connection->calls[1]['params'][':vid4']);
        self::assertStringNotContainsString(':valueId', $connection->calls[1]['sql']);

        self::assertCount(5, $values);
        self::assertSame(['def-text', 'def-empty', 'def-select', 'def-multi', 'def-unit'], array_column($values, 'definitionPublicId'));

        self::assertSame('Red', $values[0]['valueText']);
        self::assertSame([], $values[0]['optionPublicIds']);
        self::assertNull($values[0]['optionPublicId']);

        self::assertNull($values[1]['valueText']);
        self::assertNull($values[1]['valueLongText']);
        self::assertNull($values[1]['valueInteger']);
        self::assertNull($values[1]['valueDecimal']);
        self::assertNull($values[1]['valueBoolean']);
        self::assertNull($values[1]['valueDate']);
        self::assertNull($values[1]['valueUrl']);
        self::assertNull($values[1]['valueMinDecimal']);
        self::assertNull($values[1]['valueMaxDecimal']);
        self::assertSame([], $values[1]['optionPublicIds']);

        self::assertSame('opt-single', $values[2]['optionPublicId']);
        self::assertSame(['opt-single'], $values[2]['optionPublicIds']);

        self::assertNull($values[3]['optionPublicId']);
        self::assertSame(['opt-a', 'opt-b'], $values[3]['optionPublicIds']);

        self::assertSame('12.50', $values[4]['valueDecimal']);
        self::assertSame([], $values[4]['optionPublicIds']);

        foreach ($values as $value) {
            self::assertArrayHasKey('definitionPublicId', $value);
            self::assertArrayHasKey('valueText', $value);
            self::assertArrayHasKey('valueLongText', $value);
            self::assertArrayHasKey('valueInteger', $value);
            self::assertArrayHasKey('valueDecimal', $value);
            self::assertArrayHasKey('valueBoolean', $value);
            self::assertArrayHasKey('valueDate', $value);
            self::assertArrayHasKey('valueUrl', $value);
            self::assertArrayHasKey('valueMinDecimal', $value);
            self::assertArrayHasKey('valueMaxDecimal', $value);
            self::assertArrayHasKey('optionPublicId', $value);
            self::assertArrayHasKey('optionPublicIds', $value);
            self::assertArrayNotHasKey('_valueId', $value);
            self::assertArrayNotHasKey('rowVersion', $value);
        }
    }

    public function test_list_product_values_skips_option_query_when_product_has_no_values(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSpecificationRepository($connection);
        $values = $repository->listProductValues(ProductPublicId::fromString(self::PRODUCT_ID));

        self::assertSame([], $values);
        self::assertCount(1, $connection->calls);
        self::assertStringContainsString('ProductSpecificationValues', $connection->calls[0]['sql']);
    }

    public function test_list_variant_values_uses_one_batched_junction_query(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            $this->valueRow(21, 'def-a', isVariant: true),
            $this->valueRow(22, 'def-b', isVariant: true, optionPublicId: 'opt-1'),
        ]);
        $connection->queueFetch([
            ['ValueId' => 21, 'OptionPublicId' => 'opt-x'],
            ['ValueId' => 21, 'OptionPublicId' => 'opt-y'],
            ['ValueId' => 22, 'OptionPublicId' => 'opt-1'],
        ]);

        $repository = new AzureSqlProductSpecificationRepository($connection);
        $values = $repository->listVariantValues(ProductVariantPublicId::fromString(self::VARIANT_ID));

        self::assertCount(2, $connection->calls);
        self::assertStringContainsString('ProductVariantSpecificationValues', $connection->calls[0]['sql']);
        self::assertStringContainsString('ProductVariantSpecificationOptionValues', $connection->calls[1]['sql']);
        self::assertStringContainsString('IN (', $connection->calls[1]['sql']);
        self::assertSame(['opt-x', 'opt-y'], $values[0]['optionPublicIds']);
        self::assertSame(['opt-1'], $values[1]['optionPublicIds']);
        self::assertSame('opt-1', $values[1]['optionPublicId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function valueRow(
        int $valueId,
        string $definitionPublicId,
        ?string $valueText = null,
        ?string $valueDecimal = null,
        ?string $optionPublicId = null,
        bool $isVariant = false,
    ): array {
        $idColumn = $isVariant ? 'ProductVariantSpecificationValueId' : 'ProductSpecificationValueId';

        return [
            'DefinitionPublicId' => $definitionPublicId,
            $idColumn => $valueId,
            'ValueText' => $valueText,
            'ValueLongText' => null,
            'ValueInteger' => null,
            'ValueDecimal' => $valueDecimal,
            'ValueBoolean' => null,
            'ValueDate' => null,
            'ValueUrl' => null,
            'ValueMinDecimal' => null,
            'ValueMaxDecimal' => null,
            'OptionPublicId' => $optionPublicId,
        ];
    }
}
