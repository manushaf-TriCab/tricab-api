<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Service\Accessory3DDimensionSets;
use TriCab\Core\Product\Domain\Service\Accessory3DSpecificationMapping;

final class Accessory3DDimensionSetsTest extends TestCase
{
    public function test_default_set_is_the_original_terminal_list(): void
    {
        self::assertSame(Accessory3DSpecificationMapping::DIMENSION_CODES, Accessory3DDimensionSets::codes(null));
        self::assertSame(Accessory3DSpecificationMapping::DIMENSION_CODES, Accessory3DDimensionSets::codes('  '));
        self::assertSame(Accessory3DSpecificationMapping::DIMENSION_CODES, Accessory3DDimensionSets::codes('terminal'));
        self::assertContains('PIN-DIAMETER', Accessory3DDimensionSets::codes(null));
        self::assertCount(10, Accessory3DDimensionSets::codes(null));
    }

    public function test_every_audit_required_code_is_declared_by_exactly_the_sets_that_need_it(): void
    {
        self::assertContains('THREAD-OD', Accessory3DDimensionSets::codes('gland'));
        self::assertContains('CABLE-LENGTH', Accessory3DDimensionSets::codes('duct'));
        self::assertContains('CABLE-LENGTH', Accessory3DDimensionSets::codes('tape'));
        self::assertContains('DEPTH', Accessory3DDimensionSets::codes('box'));
        self::assertContains('DEPTH', Accessory3DDimensionSets::codes('tube'));
        // Not smuggled into the original set: existing clients must see no new codes there.
        self::assertNotContains('THREAD-OD', Accessory3DDimensionSets::codes('terminal'));
        self::assertNotContains('CABLE-LENGTH', Accessory3DDimensionSets::codes('terminal'));
        self::assertNotContains('DEPTH', Accessory3DDimensionSets::codes('terminal'));
    }

    public function test_sets_expose_dimensions_only(): void
    {
        foreach (Accessory3DDimensionSets::names() as $name) {
            foreach (Accessory3DDimensionSets::codes($name) as $code) {
                self::assertDoesNotMatchRegularExpression('/PRICE|COST|CURRENT|VOLTAGE|COLOUR|SKU|ERP|MATERIALS/', $code, $name . ' declares a non-dimension code ' . $code);
            }
        }
    }

    public function test_unsupported_set_is_refused(): void
    {
        self::assertFalse(Accessory3DDimensionSets::isSupported('everything'));
        $this->expectException(InvalidProductValue::class);
        Accessory3DDimensionSets::codes('everything');
    }

    public function test_mapping_only_reports_codes_of_the_requested_set(): void
    {
        $mapping = new Accessory3DSpecificationMapping();
        $specs = [
            ['code' => 'OVERALL-LENGTH', 'value' => 148, 'unit' => 'mm', 'source' => 'variant'],
            ['code' => 'THREAD-OD', 'value' => 20, 'unit' => 'mm', 'source' => 'product'],
            ['code' => 'CABLE-LENGTH', 'value' => 3, 'unit' => 'm', 'source' => 'variant'],
        ];
        $lug = $mapping->resolve($specs, 'cable-lug');
        self::assertSame(['OVERALL-LENGTH'], array_keys($lug['values']));
        self::assertContains('STUD-OD', $lug['missing']);
        self::assertNotContains('THREAD-OD', $lug['missing']);

        $gland = $mapping->resolve($specs, 'gland');
        self::assertSame(['THREAD-OD'], array_keys($gland['values']));

        $duct = $mapping->resolve($specs, 'duct');
        self::assertSame(3000.0, $duct['values']['CABLE-LENGTH']['value']);

        // Default behaviour (A06/A07/A09) is unchanged: no THREAD-OD, no CABLE-LENGTH.
        $default = $mapping->resolve($specs);
        self::assertSame(['OVERALL-LENGTH'], array_keys($default['values']));
        self::assertCount(9, $default['missing']);
    }
}
