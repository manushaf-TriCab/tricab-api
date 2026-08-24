<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryPriceListRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryVariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Application\Command\ResolveLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\Command\SetVariantLegacyPricingIdentityCommand;
use TriCab\Core\Product\Application\Service\ManageVariantLegacyPricingIdentityService;
use TriCab\Core\Product\Application\Service\ResolveLegacyVariantPricingIdentityService;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingResolutionStatus;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingResolutionSource;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Tests\Product\Doubles\FakeLegacyTcPricingRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;

final class LegacyVariantPricingIdentityServiceTest extends TestCase
{
    private const VARIANT_PUBLIC_ID = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    private string $priceListPublicId = '';

    private FakeLegacyTcPricingRepository $legacy;
    private InMemoryVariantLegacyPricingIdentityRepository $mappings;
    private ResolveLegacyVariantPricingIdentityService $resolver;
    private ManageVariantLegacyPricingIdentityService $manage;
    private InMemoryProductStore $store;

    protected function setUp(): void
    {
        $this->legacy = new FakeLegacyTcPricingRepository();
        $this->mappings = new InMemoryVariantLegacyPricingIdentityRepository();
        $priceLists = new InMemoryPriceListRepository();
        $priceList = $priceLists->create([
            'code' => 'AU-BASE',
            'name' => 'Australia Base Cost',
            'currencyCode' => 'AUD',
            'currencyPublicId' => '00000000-0000-0000-0000-0000000000au',
            'legacySourceCode' => PriceListLegacyContext::SYSTEM_CODE,
            'legacyContextType' => PriceListLegacyContext::CONTEXT_TYPE,
            'legacyContextCode' => '2507',
            'isActive' => true,
        ], \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));
        $this->priceListPublicId = $priceList->publicId;

        $this->store = new InMemoryProductStore();
        $variantRepo = $this->store->variants();
        $variant = new ProductVariant(
            id: ProductVariantId::fromInt(10),
            publicId: ProductVariantPublicId::fromString(self::VARIANT_PUBLIC_ID),
            productId: ProductId::fromInt(1),
            variantName: '10A 16mm² Black',
            status: ProductStatusCode::fromString('published'),
            displayOrder: DisplayOrder::fromInt(1),
            variantCode: ProductCodeValue::fromString('RRA-CAXD/10A16BK'),
        );
        $variantRepo->save($variant, \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));
        $this->mappings->registerVariantId(10, self::VARIANT_PUBLIC_ID);

        $this->resolver = new ResolveLegacyVariantPricingIdentityService(
            $variantRepo,
            $this->mappings,
            $this->legacy,
            $priceLists,
            new LegacyPricingMaskGenerator(),
        );
        $this->manage = new ManageVariantLegacyPricingIdentityService(
            $variantRepo,
            $this->store->products(),
            $this->mappings,
            $this->legacy,
            $priceLists,
            new \TriCab\Core\Product\Application\Service\ProductAuditRecorder($this->store->audit()),
        );
    }

    public function test_exact_variant_code_match(): void
    {
        $this->legacy->seedPrice(2507, 'RRA-CAXD/10A16BK', '12.50');

        $resolution = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
            self::VARIANT_PUBLIC_ID,
            $this->priceListPublicId,
        ));

        self::assertSame(LegacyPricingResolutionStatus::EXACT_CODE, $resolution->resolutionStatus);
        self::assertSame('RRA-CAXD/10A16BK', $resolution->legacyPriceCode);
        self::assertSame(LegacyPricingResolutionSource::EXACT, $resolution->resolutionSource);
    }

    public function test_mask_match_when_exact_missing(): void
    {
        $this->legacy->seedPrice(2507, 'RRA-XXXD/10A16BK', '11.00');

        $resolution = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
            self::VARIANT_PUBLIC_ID,
            $this->priceListPublicId,
        ));

        self::assertSame(LegacyPricingResolutionStatus::MASK, $resolution->resolutionStatus);
        self::assertSame('RRA-XXXD/10A16BK', $resolution->legacyPriceCode);
    }

    public function test_explicit_mapping_wins_over_mask(): void
    {
        $this->legacy->seedPrice(2507, 'RRA-XXXD/10A16BK', '11.00');
        $this->legacy->seedPrice(2507, 'CUSTOM-CODE', '9.00');
        $actor = new ActorContext(1, '00000000-0000-0000-0000-000000000001', 'test@test.com');
        $this->manage->set($actor, new SetVariantLegacyPricingIdentityCommand(
            self::VARIANT_PUBLIC_ID,
            'CUSTOM-CODE',
            $this->priceListPublicId,
        ));

        $resolution = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
            self::VARIANT_PUBLIC_ID,
            $this->priceListPublicId,
        ));

        self::assertSame(LegacyPricingResolutionStatus::EXPLICIT, $resolution->resolutionStatus);
        self::assertSame('CUSTOM-CODE', $resolution->legacyPriceCode);
    }

    public function test_zero_cost_does_not_invalidate_identity(): void
    {
        $this->legacy->seedPrice(2507, 'RRA-CAXD/10A16BK', '0');

        $resolution = $this->resolver->execute(new ResolveLegacyVariantPricingIdentityCommand(
            self::VARIANT_PUBLIC_ID,
            $this->priceListPublicId,
        ));

        self::assertSame(LegacyPricingResolutionStatus::EXACT_CODE, $resolution->resolutionStatus);
        self::assertSame('ZERO_COST', $resolution->priceDataStatus);
        self::assertNotEmpty($resolution->warnings);
    }
}
