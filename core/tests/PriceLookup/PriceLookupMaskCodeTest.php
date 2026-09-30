<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\PriceLookup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\PriceLookup\Persistence\InMemoryPriceLookupFxRepository;
use TriCab\Api\PriceLookup\Persistence\InMemoryPriceLookupPriceRepository;
use TriCab\Api\Product\Pricing\FakeLegacyCostCalculationGateway;
use TriCab\Core\PriceLookup\Application\DTO\PriceLookupQuoteResult;
use TriCab\Core\PriceLookup\Application\DTO\PriceLookupSearchHit;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupProductNotFound;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Application\Service\ListPriceLookupCodesService;
use TriCab\Core\PriceLookup\Application\Service\QuotePriceLookupService;
use TriCab\Core\PriceLookup\Application\Service\SearchPriceLookupService;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;
use TriCab\Core\PriceLookup\Domain\PriceLookupDbcoMapper;

/**
 * Mask-code fallback and rejection (PRICE-LOOKUP-MASK-CODE-WORK-ORDER.md, section 5 matrix
 * plus the 29 Sep decisions: jacket styles, .75 and AWG sizes, and XX codes only for the
 * single-insulated LS, LP and UP styles).
 * The cost API fake has no seeds, so every quote prices from tc.price like a disabled gateway.
 */
final class PriceLookupMaskCodeTest extends TestCase
{
    private InMemoryPriceLookupPriceRepository $prices;
    private FakeLegacyCostCalculationGateway $costApi;

    protected function setUp(): void
    {
        $this->prices = new InMemoryPriceLookupPriceRepository();
        $tca = [
            'KL-PAXN/5C10BK' => '12.50',
            'KL-XXXN/5C16' => '20.00',
            'KL-XXXD/1C120' => '28.24',
            'KL-XXSN/4C16' => '30.00',
            'KL-XXXN/4C16' => '99.00',
            'KL-XXXN/1C10' => '5.00',
            'KL-XXXN/1C100' => '50.00',
            'LS-XXXN/1C120BK' => '20.953',
            'KL-XXXN/32C1.5' => '15.00',
            'UP-XXXD/1C120' => '40.00',
            'UPA-XXXD/1C70' => '35.00',
            'UP-XXXD/1C70' => '34.00',
            'UP-XXXD/1C.75' => '2.00',
            'KL-XXXN/6C2.5BK' => '8.00',
            'LP-XXXN/1C16BK' => '3.00',
        ];
        foreach ($tca as $code => $cost) {
            $this->seedPrice($code, $cost, PriceLookupDbcoMapper::DBCO_TCA);
        }
        foreach (['JD-XXXD/1C4' => '6.50', 'JD-XXXD/1A4' => '1.30', 'JD-XXXN/1A4/0' => '13.00'] as $code => $cost) {
            $this->seedPrice($code, $cost, PriceLookupDbcoMapper::DBCO_TCUS);
        }
        $this->prices->seedWarehouseStatus('KL-PAXN/5C16OR', '2302');

        $this->costApi = new FakeLegacyCostCalculationGateway();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function masks(): iterable
    {
        yield 'colour' => ['KL-PAXN/5C10BK', 'KL-XXXN/5C10'];
        yield 'armour kept' => ['KL-PASN/4C16OR', 'KL-XXSN/4C16'];
        yield 'decimal size' => ['KL-CAXN/32C1.5BK', 'KL-XXXN/32C1.5'];
        yield 'pairs' => ['R3-DEXN/12P1.5WH', 'R3-XXXN/12P1.5'];
        yield 'colour with a digit' => ['KL-PAXN/3C2.5G1', 'KL-XXXN/3C2.5'];
        yield 'no leading zero' => ['UPA-XXXD/1C.75BK', 'UPA-XXXD/1C.75'];
        yield 'awg' => ['JD-XXXN/1A18BL', 'JD-XXXN/1A18'];
        yield 'awg aught' => ['JD-XXXN/1A4/0BK', 'JD-XXXN/1A4/0'];
        yield 'green/yellow earth' => ['CS-XXXN/1C10G/Y', 'CS-XXXN/1C10'];
        yield 'lower case, padded' => [' kl-paxn/5c10bk ', 'KL-XXXN/5C10'];
    }

    #[DataProvider('masks')]
    public function test_mask_for_lookup(string $code, string $mask): void
    {
        self::assertSame($mask, (new PriceLookupColourMask())->maskForLookup($code));
    }

    public function test_mask_for_lookup_is_null_for_partial_codes_and_wildcards(): void
    {
        $colourMask = new PriceLookupColourMask();
        foreach (['KL-PA', 'KL-PA*', '*', 'KL-', 'BHA-CATD/19C1.RE'] as $code) {
            self::assertNull($colourMask->maskForLookup($code), $code);
        }
    }

    public function test_jacket_styles_fall_back_to_their_base_style(): void
    {
        $colourMask = new PriceLookupColourMask();
        self::assertSame(['UPA-XXXD/1C120', 'UP-XXXD/1C120'], $colourMask->masksForLookup('UPA-XXXD/1C120BK'));
        self::assertSame(['FKM-XXXN/1C16', 'FK-XXXN/1C16'], $colourMask->masksForLookup('FKM-PAXN/1C16BK'));
        self::assertSame(['KL-XXXN/5C10'], $colourMask->masksForLookup('KL-PAXN/5C10BK'), 'two-letter style has no jacket');
        self::assertSame(['KLT-XXXN/5C10'], $colourMask->masksForLookup('KLT-PAXN/5C10BK'), 'T is not a jacket letter');
    }

    public function test_is_mask(): void
    {
        $colourMask = new PriceLookupColourMask();
        foreach (['KL-XXXN/5C10', ' kl-xxxn/1c1.5 ', 'KF-XXXN/1C25', 'UPA-XXXD/1C120', 'JD-XXXD/1C4', 'JD-XXXD/1A4', 'JD-XXXN/1A4/0', 'UP-XXXD/1C.75'] as $code) {
            self::assertTrue($colourMask->isMask($code), $code);
        }
        foreach (['LS-XXXN/1C120BK', 'KL-PAXN/5C10', 'KL-XX*', 'KL-XXXN/5C10BK'] as $code) {
            self::assertFalse($colourMask->isMask($code), $code);
        }
    }

    public function test_a_priced_cable_uses_its_own_price(): void
    {
        self::assertSame([['KL-PAXN/5C10BK', null]], $this->searchHits('KL-PAXN/5C10BK'));

        $quote = $this->quote('KL-PAXN/5C10BK');
        self::assertSame('12.50', $quote->adjustedCost);
        self::assertNull($quote->pricedFromMask);
    }

    public function test_b_unpriced_cable_is_priced_from_its_mask_with_its_own_status(): void
    {
        $hits = $this->search('KL-PAXN/5C16OR');
        self::assertCount(1, $hits);
        self::assertSame('KL-PAXN/5C16OR', $hits[0]->productCode);
        self::assertSame('KL-XXXN/5C16', $hits[0]->pricedFromMask);
        self::assertSame('STOCK', $hits[0]->status);

        $quote = $this->quote('KL-PAXN/5C16OR');
        self::assertSame('20.00', $quote->adjustedCost);
        self::assertSame('KL-PAXN/5C16OR', $quote->productCode);
        self::assertSame('KL-XXXN/5C16', $quote->pricedFromMask);
        self::assertSame('STOCK', $quote->status);
    }

    public function test_c_armoured_cable_keeps_its_armour_letter(): void
    {
        self::assertSame([['KL-PASN/4C16BK', 'KL-XXSN/4C16']], $this->searchHits('KL-PASN/4C16BK'));
        self::assertSame('30.00', $this->quote('KL-PASN/4C16BK')->adjustedCost);
    }

    public function test_d_mask_is_matched_exactly(): void
    {
        self::assertSame([['KL-PAXN/1C10BK', 'KL-XXXN/1C10']], $this->searchHits('KL-PAXN/1C10BK'));
        self::assertSame('5.00', $this->quote('KL-PAXN/1C10BK')->adjustedCost);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function maskCodes(): iterable
    {
        yield 'e: mask' => ['KL-XXXN/5C16', 'TCA'];
        yield 'f: mask, lower case' => ['kl-xxxn/5c16', 'TCA'];
        yield 'kf mask' => ['KF-XXXN/1C25', 'TCA'];
        yield 'jacket mask' => ['UPA-XXXD/1C120', 'TCA'];
        yield 'jd mask in TCUS' => ['JD-XXXD/1C4', 'TCUS'];
        yield 'jd awg mask in TCUS' => ['JD-XXXD/1A4', 'TCUS'];
    }

    #[DataProvider('maskCodes')]
    public function test_mask_codes_are_rejected(string $code, string $company): void
    {
        foreach ([fn () => $this->search($code, $company), fn () => $this->quote($code, $company)] as $lookup) {
            try {
                $lookup();
                self::fail('Expected MASK_CODE_NOT_ALLOWED for ' . $code);
            } catch (PriceLookupValidationException $e) {
                self::assertSame('MASK_CODE_NOT_ALLOWED', $e->errorCode);
                self::assertStringStartsWith(strtoupper($code) . ' is a mask code', $e->getMessage());
            }
        }
    }

    public function test_g_wildcard_over_masks_finds_nothing(): void
    {
        $this->assertNotFound(fn () => $this->search('KL-XX*'));
        $this->assertNotFound(fn () => $this->quote('KL-XX*'));
    }

    public function test_h_xx_code_with_a_colour_is_a_real_product(): void
    {
        self::assertSame([['LS-XXXN/1C120BK', null]], $this->searchHits('LS-XXXN/1C120BK'));

        $quote = $this->quote('LS-XXXN/1C120BK');
        self::assertSame('20.95', $quote->adjustedCost);
        self::assertNull($quote->pricedFromMask);
    }

    public function test_i_no_price_and_no_mask_is_not_found_naming_the_entered_code(): void
    {
        $this->assertNotFound(fn () => $this->search('KL-PAXN/7C25BK'));
        $this->assertNotFound(fn () => $this->quote('KL-PAXN/7C25BK'), 'Product "KL-PAXN/7C25BK" was not found.');
    }

    public function test_j_prefix_search_leaves_masks_out(): void
    {
        self::assertSame([['KL-PAXN/5C10BK', null]], $this->searchHits('KL-'));
        self::assertSame([['LS-XXXN/1C120BK', null]], $this->searchHits('LS-XX*'));
    }

    public function test_k_mask_fallback_prices_setup_premium_from_the_mask(): void
    {
        self::assertSame([['KL-CAXN/32C1.5BK', 'KL-XXXN/32C1.5']], $this->searchHits('KL-CAXN/32C1.5BK'));

        $quote = $this->quote('KL-CAXN/32C1.5BK');
        self::assertSame('15.00', $quote->adjustedCost);
        self::assertSame('1206.00', $quote->setupPremium);
    }

    public function test_mask_fallback_asks_the_cost_api_for_the_mask_code(): void
    {
        $this->quote('KL-PAXN/5C16OR');

        self::assertSame('KL-XXXN/5C16', $this->costApi->calls[0]['productCode'] ?? null);
    }

    public function test_jacket_style_falls_back_to_the_base_style_mask(): void
    {
        self::assertSame([['UPA-XXXD/1C120BK', 'UP-XXXD/1C120']], $this->searchHits('UPA-XXXD/1C120BK'));

        $quote = $this->quote('UPA-XXXD/1C120BK');
        self::assertSame('40.00', $quote->adjustedCost);
        self::assertSame('UP-XXXD/1C120', $quote->pricedFromMask);
    }

    public function test_jacket_style_prefers_its_own_mask(): void
    {
        $quote = $this->quote('UPA-XXXD/1C70BK');
        self::assertSame('35.00', $quote->adjustedCost);
        self::assertSame('UPA-XXXD/1C70', $quote->pricedFromMask);
    }

    public function test_size_without_a_leading_zero(): void
    {
        $quote = $this->quote('UPA-XXXD/1C.75BK');
        self::assertSame('2.00', $quote->adjustedCost);
        self::assertSame('UP-XXXD/1C.75', $quote->pricedFromMask);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function xxCodesOfOtherStyles(): iterable
    {
        yield 'unpriced, has a mask' => ['KL-XXXN/4C120OR', 'TCA'];
        yield 'has its own price row' => ['KL-XXXN/6C2.5BK', 'TCA'];
        yield 'lower case' => ['kl-xxxn/4c120or', 'TCA'];
        yield 'armour letter' => ['KA-XXJN/1C35EA', 'TCA'];
        yield 'jd in TCUS' => ['JD-XXXD/1C4EA', 'TCUS'];
        yield 'jd awg in TCUS' => ['JD-XXXD/1A4BK', 'TCUS'];
        yield 'ls1 is not an LS variant' => ['LS1-XXXN/1C16BK', 'TCA'];
        yield 'partial code' => ['KL-XXXN/4', 'TCA'];
    }

    #[DataProvider('xxCodesOfOtherStyles')]
    public function test_xx_codes_are_only_valid_for_ls_lp_and_up(string $code, string $company): void
    {
        foreach ([fn () => $this->search($code, $company), fn () => $this->quote($code, $company)] as $lookup) {
            try {
                $lookup();
                self::fail('Expected INVALID_PRODUCT_CODE for ' . $code);
            } catch (PriceLookupValidationException $e) {
                self::assertSame('INVALID_PRODUCT_CODE', $e->errorCode);
                self::assertSame(strtoupper($code) . ' is not a valid product code.', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unfinishedCodes(): iterable
    {
        yield 'mask has a price' => ['KL-PBXD/1C120', 'TCA'];
        yield 'lower case' => ['kl-pbxd/1c120', 'TCA'];
        yield 'jacket style' => ['UPA-PAXD/1C120', 'TCA'];
        yield 'no mask either' => ['KL-PAXN/7C25', 'TCA'];
    }

    #[DataProvider('unfinishedCodes')]
    public function test_a_colourless_code_that_is_not_a_product_is_rejected(string $code, string $company): void
    {
        foreach ([fn () => $this->search($code, $company), fn () => $this->quote($code, $company)] as $lookup) {
            try {
                $lookup();
                self::fail('Expected INVALID_PRODUCT_CODE for ' . $code);
            } catch (PriceLookupValidationException $e) {
                self::assertSame('INVALID_PRODUCT_CODE', $e->errorCode);
                self::assertSame(strtoupper($code) . ' is missing its colour. Please enter the full product code.', $e->getMessage());
            }
        }
    }

    public function test_a_colourless_product_is_still_priced_from_its_mask(): void
    {
        $this->prices->seedProduct('KL-PBXD/1C120');

        self::assertSame([['KL-PBXD/1C120', 'KL-XXXD/1C120']], $this->searchHits('KL-PBXD/1C120'));
        self::assertSame('KL-XXXD/1C120', $this->quote('KL-PBXD/1C120')->pricedFromMask);
    }

    public function test_a_colourless_code_with_its_own_price_is_quoted(): void
    {
        $this->seedPrice('KL-PAXN/9C10', '7.00', PriceLookupDbcoMapper::DBCO_TCA);

        self::assertSame('7.00', $this->quote('KL-PAXN/9C10')->adjustedCost);
    }

    public function test_is_colourless(): void
    {
        $colourMask = new PriceLookupColourMask();
        foreach (['KL-PBXD/1C120', ' kl-paxn/5c10 ', 'JD-PAXN/1A4/0', 'KL-XXXN/5C10'] as $code) {
            self::assertTrue($colourMask->isColourless($code), $code);
        }
        foreach (['KL-PBXD/1C120BK', 'KL-PBXD/1C120*', 'KL-PB', 'CS-PAXN/1C10G/Y'] as $code) {
            self::assertFalse($colourMask->isColourless($code), $code);
        }
    }

    public function test_ls_lp_and_up_variants_can_have_xx_codes(): void
    {
        $colourMask = new PriceLookupColourMask();
        foreach (['LS-XXXN/1C120BK', 'LP-XXXN/1C16BK', 'UP-XXXD/1C120BK', 'UPA-XXXD/1C120BK', 'UPB-XXXD/1C16BK', 'UPC-XXXD/1C16BK', 'UPM-XXXD/1C16BK', 'KL-PAXN/5C10BK', 'KL-XX*'] as $code) {
            self::assertFalse($colourMask->isXxCodeOfOtherStyle($code), $code);
        }

        self::assertSame('3.00', $this->quote('LP-XXXN/1C16BK')->adjustedCost);
    }

    public function test_prefix_search_leaves_out_xx_codes_of_other_styles(): void
    {
        self::assertSame([['KL-PAXN/5C10BK', null]], $this->searchHits('KL-'));
        $this->assertNotFound(fn () => $this->search('KL-XX*'));
    }

    public function test_masks_of_other_styles_still_price_their_cables(): void
    {
        self::assertSame('KL-XXXN/5C16', $this->quote('KL-PAXN/5C16OR')->pricedFromMask);
    }

    public function test_code_list_is_the_product_master_without_masks_or_other_styles_xx_codes(): void
    {
        $this->prices->seedProduct('KL-PAXN/5C10BK');
        $this->prices->seedProduct('ls-xxxn/1c120bk');
        $this->prices->seedProduct('JD-XXXD/1C4EA');
        $this->prices->seedProduct('KL-XXXN/6C2.5BK');
        $this->prices->seedProduct('UPA-XXXD/1C120BK');
        $this->prices->seedProduct('KL-XXXN/5C16');

        self::assertSame(
            ['KL-PAXN/5C10BK', 'KL-PAXN/5C16OR', 'LS-XXXN/1C120BK', 'UPA-XXXD/1C120BK'],
            (new ListPriceLookupCodesService($this->prices))->execute(),
        );
    }

    public function test_tcus_prefix_search_leaves_masks_out(): void
    {
        $this->assertNotFound(fn () => $this->search('JD-', 'TCUS'));
    }

    private function seedPrice(string $code, string $cost, string $dbco): void
    {
        $this->prices->seed([
            'productCode' => $code,
            'cost' => $cost,
            'setupLength' => $code === 'LS-XXXN/1C120BK' ? '600' : '500',
            'lastUpdated' => "1st Sep'26",
            'warehouseStatus' => null,
            'dbco' => $dbco,
        ]);
    }

    /**
     * @return list<PriceLookupSearchHit>
     */
    private function search(string $query, string $company = 'TCA'): array
    {
        return (new SearchPriceLookupService($this->prices))->execute($query, $company, [])->items;
    }

    /**
     * @return list<array{string, string|null}>
     */
    private function searchHits(string $query, string $company = 'TCA'): array
    {
        return array_map(
            static fn (PriceLookupSearchHit $hit): array => [$hit->productCode, $hit->pricedFromMask],
            $this->search($query, $company),
        );
    }

    private function quote(
        string $code,
        string $company = 'TCA',
        string $currency = 'AUD',
        string $metric = 'metre',
    ): PriceLookupQuoteResult {
        $service = new QuotePriceLookupService($this->prices, new InMemoryPriceLookupFxRepository(), $this->costApi);

        return $service->execute($code, $company, $currency, $metric);
    }

    private function assertNotFound(callable $lookup, ?string $message = null): void
    {
        try {
            $lookup();
            self::fail('Expected PriceLookupProductNotFound');
        } catch (PriceLookupProductNotFound $e) {
            $this->addToAssertionCount(1);
            if ($message !== null) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
