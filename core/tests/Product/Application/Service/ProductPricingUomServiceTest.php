<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPricingRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\PricingUnitDefaults;

final class ProductPricingUomServiceTest extends TestCase
{
    private const METRE_UNIT = '00000000-0000-0000-0000-0000000000m1';
    private const EACH_UNIT = '00000000-0000-0000-0000-0000000000ea';

    public function test_product_default_and_row_override_resolution(): void
    {
        $store = new InMemoryProductStore();
        $pricing = new InMemoryProductPricingRepository($store);
        $productPublicId = ProductPublicId::fromString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        $actor = UserId::fromInt(1);

        $overview = $pricing->setProductDefaultPricingUnit($productPublicId, self::EACH_UNIT, $actor);
        self::assertSame('EA', $overview->defaultUnitOfMeasureCode);
        self::assertSame('product', $overview->defaultUnitSource);

        $saved = $pricing->upsert($productPublicId, [
            'currencyCode' => 'AUD',
            'baseCost' => '3.50',
            'minimumQuantity' => '1',
            'unitOfMeasurePublicId' => self::METRE_UNIT,
            'createRevision' => true,
        ], $actor);
        self::assertSame('M', $saved->unitOfMeasureCode);
        self::assertSame('m', $saved->unitOfMeasureSymbol);

        $inherited = $pricing->upsert($productPublicId, [
            'currencyCode' => 'AUD',
            'baseCost' => '4.00',
            'minimumQuantity' => '1',
            'createRevision' => true,
        ], $actor);
        self::assertSame('EA', $inherited->unitOfMeasureCode);
        self::assertSame('ea', $inherited->unitOfMeasureSymbol);
    }

    public function test_system_default_when_no_product_unit(): void
    {
        $pricing = new InMemoryProductPricingRepository(new InMemoryProductStore());
        $overview = $pricing->getOverview(ProductPublicId::fromString('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'));

        self::assertSame(PricingUnitDefaults::CODE, $overview->defaultUnitOfMeasureCode);
        self::assertSame('system-default', $overview->defaultUnitSource);
    }
}
