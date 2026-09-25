<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\QuoteOrder\Mapping;

use PHPUnit\Framework\TestCase;
use TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteLineTypeDefaults;

final class LegacyQuoteOrderMapperDescriptionTest extends TestCase
{
    private LegacyQuoteOrderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LegacyQuoteOrderMapper();
    }

    public function test_cable_description_uses_style_and_desc2_not_colour_alone(): void
    {
        $item = $this->mapper->mapItem([
            'item_id' => '10',
            'item_no' => '2',
            'product_code' => 'XU-PAZN/4C120BK',
            'category_type' => LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE,
            'quantity' => '160',
            'pack_count' => '160',
            'unit_price' => '12.50',
            'line_total' => '2000.00',
            'colour_name' => 'Black',
            'style_description' => 'Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1',
            'product_description_2' => '(RE,WH,BL,EA)',
            'weight_kg' => null,
        ]);

        self::assertSame('Black', $item->colourName);
        self::assertSame(
            'Black Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1 - (RE,WH,BL,EA)',
            $item->description,
        );
        self::assertSame('XU-PAZN/4C120BK', $item->productCode);
        self::assertSame('160m', $item->packLabel);
        self::assertSame('160', $item->quantity);
        self::assertSame('12.50', $item->unitPrice);
        self::assertSame('2000.00', $item->lineTotal);
    }

    public function test_colour_name_is_not_overwritten_when_style_description_exists(): void
    {
        $item = $this->mapper->mapItem([
            'item_id' => '11',
            'item_no' => '3',
            'product_code' => 'XU-PAZN/4C120BK',
            'category_type' => LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE,
            'quantity' => '10',
            'pack_count' => '10',
            'unit_price' => '1.00',
            'line_total' => '10.00',
            'colour_name' => 'BLACK',
            'style_description' => 'Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1',
            'product_description_2' => null,
        ]);

        self::assertSame('BLACK', $item->colourName);
        self::assertSame(
            'Black Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1',
            $item->description,
        );
        self::assertStringNotContainsString('BLACK', (string) $item->description);
    }

    public function test_accessory_description_uses_style_name(): void
    {
        $item = $this->mapper->mapItem([
            'item_id' => '20',
            'item_no' => '10',
            'product_code' => 'B06-AJXX/10H120',
            'category_type' => LegacyQuoteLineTypeDefaults::ACCESSORY_CATEGORY_TYPE,
            'quantity' => '1',
            'pack_count' => '1',
            'unit_price' => '5.00',
            'line_total' => '5.00',
            'colour_name' => null,
            'style_description' => 'Aluminium Cable Gland',
            'product_description_2' => null,
        ]);

        self::assertNull($item->colourName);
        self::assertSame('Aluminium Cable Gland', $item->description);
        self::assertSame('B06-AJXX/10H120', $item->productCode);
        self::assertSame('1 pack', $item->packLabel);
        self::assertSame('5.00', $item->unitPrice);
        self::assertSame('5.00', $item->lineTotal);
    }

    public function test_falls_back_to_colour_when_no_style_or_desc2(): void
    {
        $item = $this->mapper->mapItem([
            'item_id' => '30',
            'item_no' => '1',
            'product_code' => 'UNKNOWN-CODE',
            'category_type' => LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE,
            'quantity' => '5',
            'pack_count' => '5',
            'unit_price' => '1.00',
            'line_total' => '5.00',
            'colour_name' => 'Red',
            'style_description' => null,
            'product_description_2' => null,
        ]);

        self::assertSame('Red', $item->colourName);
        self::assertSame('Red', $item->description);
    }

    public function test_falls_back_to_null_when_no_descriptive_fields(): void
    {
        $item = $this->mapper->mapItem([
            'item_id' => '31',
            'item_no' => '1',
            'product_code' => 'ZZUNKNOWN',
            'category_type' => LegacyQuoteLineTypeDefaults::ACCESSORY_CATEGORY_TYPE,
            'quantity' => '1',
            'pack_count' => '1',
            'unit_price' => '1.00',
            'line_total' => '1.00',
            'colour_name' => null,
            'style_description' => null,
            'product_description_2' => null,
        ]);

        self::assertNull($item->colourName);
        self::assertNull($item->description);
        self::assertSame('ZZUNKNOWN', $item->productCode);
    }

    public function test_historical_quote_line_has_no_description_snapshot_uses_live_style(): void
    {
        // tqoti has no quote-time description column; legacy PDFs always join live
        // descript (type 24) + tpro_ext.desc2 — same behaviour here.
        $item = $this->mapper->mapItem([
            'item_id' => '40',
            'item_no' => '2',
            'product_code' => 'XU-PAZN/4C120BK',
            'category_type' => LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE,
            'quantity' => '160',
            'pack_count' => '160',
            'unit_price' => '12.50',
            'line_total' => '2000.00',
            'colour_name' => 'Black',
            'style_description' => 'Flexible Rubber EMC X-90 0.6/1KV 90C AS/NZS 5000.1',
            'product_description_2' => '(RE,WH,BL,EA)',
        ]);

        self::assertNotSame('Black', $item->description);
        self::assertStringContainsString('Flexible Rubber EMC', (string) $item->description);
        self::assertSame('Black', $item->colourName);
    }
}
