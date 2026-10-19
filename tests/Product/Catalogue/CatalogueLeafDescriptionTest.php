<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Catalogue;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Catalogue\CatalogueLeafDescription;

final class CatalogueLeafDescriptionTest extends TestCase
{
    public function test_saved_description_wins_over_product_name(): void
    {
        self::assertSame(
            'Shipboard Power Cables',
            CatalogueLeafDescription::resolve('Shipboard Power Cables', 'Shipboard ALI-FLEX Power Cable', 'BV'),
        );
    }

    public function test_blank_description_falls_back_to_single_product_name(): void
    {
        self::assertSame(
            'Flexible Nylon Extruded Termite Resistant SDI',
            CatalogueLeafDescription::resolve('  ', ' Flexible Nylon Extruded Termite Resistant SDI ', 'LN'),
        );
        self::assertSame(
            'Flexible PVC Earth 105°C',
            CatalogueLeafDescription::resolve(null, 'Flexible PVC Earth 105°C', 'LP'),
        );
    }

    public function test_no_subtitle_without_a_single_product(): void
    {
        self::assertNull(CatalogueLeafDescription::resolve(null, null, 'Copper'));
        self::assertNull(CatalogueLeafDescription::resolve('', '', 'Copper'));
    }

    public function test_product_name_repeating_the_node_name_is_dropped(): void
    {
        self::assertNull(CatalogueLeafDescription::resolve(null, 'Medium Duty', 'Medium Duty'));
        self::assertNull(CatalogueLeafDescription::resolve(null, 'medium duty', ' Medium Duty '));
    }
}
