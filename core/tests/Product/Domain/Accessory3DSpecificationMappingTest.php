<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Service\Accessory3DSpecificationMapping;

final class Accessory3DSpecificationMappingTest extends TestCase
{
    public function test_converts_units_to_millimetres_and_reports_missing(): void
    {
        $result = (new Accessory3DSpecificationMapping())->resolve([
            ['code' => 'overall-length', 'value' => 4.5, 'unit' => 'cm', 'source' => 'product'],
            ['code' => 'WIDTH', 'value' => '0.5 in', 'unit' => 'in', 'source' => 'variant'],
            ['code' => 'THICKNESS', 'value' => 3, 'unit' => null],
            ['code' => 'HEIGHT', 'value' => 0.02, 'unit' => 'm'],
            ['code' => 'MATERIALS', 'displayValue' => ' Tinned copper ', 'value' => null],
            ['code' => 'NOM-CABLE-OD', 'value' => 9, 'unit' => 'mm'],
        ]);

        self::assertSame(45.0, $result['values']['OVERALL-LENGTH']['value']);
        self::assertSame('mm', $result['values']['OVERALL-LENGTH']['unit']);
        self::assertSame('OVERALL-LENGTH', $result['values']['OVERALL-LENGTH']['definitionCode']);
        self::assertSame('product', $result['values']['OVERALL-LENGTH']['source']);
        self::assertEqualsWithDelta(12.7, $result['values']['WIDTH']['value'], 0.0001);
        self::assertSame('variant', $result['values']['WIDTH']['source']);
        self::assertSame(3.0, $result['values']['THICKNESS']['value']);
        self::assertEqualsWithDelta(20.0, $result['values']['HEIGHT']['value'], 0.0001);
        self::assertSame('Tinned copper', $result['values']['MATERIALS']['value']);
        self::assertNull($result['values']['MATERIALS']['unit']);
        self::assertArrayNotHasKey('NOM-CABLE-OD', $result['values']);
        self::assertContains('BARREL-LENGTH', $result['missing']);
        self::assertNotContains('WIDTH', $result['missing']);
        self::assertTrue($result['canGenerate']);
    }

    public function test_inch_conversion(): void
    {
        $result = (new Accessory3DSpecificationMapping())->resolve([
            ['code' => 'WIDTH', 'value' => 2, 'unit' => 'in'],
        ]);

        self::assertEqualsWithDelta(50.8, $result['values']['WIDTH']['value'], 0.0001);
    }

    public function test_empty_input_cannot_generate_and_materials_alone_is_not_enough(): void
    {
        $mapping = new Accessory3DSpecificationMapping();
        $empty = $mapping->resolve([]);
        self::assertFalse($empty['canGenerate']);
        self::assertSame([], $empty['values']);
        self::assertCount(9, $empty['missing']);

        $materialsOnly = $mapping->resolve([['code' => 'MATERIALS', 'value' => 'Brass']]);
        self::assertFalse($materialsOnly['canGenerate']);
    }

    public function test_source_hash_is_stable_order_independent_and_value_sensitive(): void
    {
        $mapping = new Accessory3DSpecificationMapping();
        $a = ['code' => 'OVERALL-LENGTH', 'value' => 40, 'unit' => 'mm'];
        $b = ['code' => 'WIDTH', 'value' => 10, 'unit' => 'mm'];

        $one = $mapping->resolve([$a, $b])['sourceHash'];
        self::assertSame($one, $mapping->resolve([$b, $a])['sourceHash']);
        self::assertSame($one, $mapping->resolve([$a, $b])['sourceHash']);
        self::assertMatchesRegularExpression('/^fnv1a32:[0-9a-f]{8}$/', $one);
        self::assertNotSame($one, $mapping->resolve([$a, ['code' => 'WIDTH', 'value' => 11, 'unit' => 'mm']])['sourceHash']);
    }
}
