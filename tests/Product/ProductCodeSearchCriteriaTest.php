<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;

final class ProductCodeSearchCriteriaTest extends TestCase
{
    public function test_a_facet_only_search_is_valid(): void
    {
        $criteria = new ProductCodeSearchCriteria(isLszh: true);

        self::assertNull($criteria->normalizedText());
        self::assertSame([], $criteria->textTokens());
    }

    public function test_text_is_split_into_tokens(): void
    {
        $criteria = new ProductCodeSearchCriteria(text: "  marine\t3.3kv  lszh ");

        self::assertSame('marine	3.3kv  lszh', $criteria->normalizedText());
        self::assertSame(['marine', '3.3kv', 'lszh'], $criteria->textTokens());
    }

    public function test_style_codes_and_stock_statuses_are_upper_cased_and_deduplicated(): void
    {
        $criteria = new ProductCodeSearchCriteria(
            styleCodes: ['bh', 'BH', ' bv '],
            stockStatuses: ['stock', 'STOCK', 'make'],
        );

        self::assertSame(['BH', 'BV'], $criteria->normalizedStyleCodes());
        self::assertSame(['STOCK', 'MAKE'], $criteria->normalizedStockStatuses());
    }

    public function test_an_unknown_stock_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductCodeSearchCriteria(stockStatuses: ['IN STOCK']);
    }

    public function test_an_inverted_size_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crossSectionMm2Min must not exceed crossSectionMm2Max.');

        new ProductCodeSearchCriteria(crossSectionMm2Min: 25.0, crossSectionMm2Max: 4.0);
    }

    public function test_an_inverted_core_count_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('coreCountMin must not exceed coreCountMax.');

        new ProductCodeSearchCriteria(coreCountMin: 7, coreCountMax: 4);
    }

    public function test_page_size_is_capped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductCodeSearchCriteria(pageSize: 500);
    }
}
