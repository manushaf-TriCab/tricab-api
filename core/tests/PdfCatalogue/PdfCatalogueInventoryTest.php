<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\PdfCatalogue;

use PHPUnit\Framework\TestCase;
use TriCab\Core\PdfCatalogue\Domain\PdfCatalogueInventory;

final class PdfCatalogueInventoryTest extends TestCase
{
    public function test_definitions_are_unique_and_pur_marine_is_multi_region(): void
    {
        $defs = PdfCatalogueInventory::definitions();
        self::assertCount(10, $defs);

        $seedKeys = array_column($defs, 'seedKey');
        self::assertSame($seedKeys, array_values(array_unique($seedKeys)));

        $pur = null;
        foreach ($defs as $def) {
            if ($def['seedKey'] === 'pur-marine') {
                $pur = $def;
                break;
            }
        }
        self::assertNotNull($pur);
        self::assertSame(['AU_NZ', 'ASIA'], $pur['regions']);
        self::assertSame('catalogues/pur-marine/12.0/catalogue.pdf', $pur['documentBlobPath']);

        $sortOrders = array_column($defs, 'sortOrder');
        $sorted = $sortOrders;
        sort($sorted);
        self::assertSame($sorted, $sortOrders);
    }
}
