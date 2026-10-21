<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Service\Cable3DSpecificationMapping;
use TriCab\Core\Product\Domain\Service\Product3DViewerConfigurationValidator;

final class Product3DStudioDomainTest extends TestCase
{
    public function test_viewer_configuration_defaults_and_merge(): void
    {
        $validator = new Product3DViewerConfigurationValidator();
        $result = $validator->validate([
            'version' => 1,
            'controls' => ['autoRotate' => false],
            'environment' => ['backgroundColor' => '#111111'],
        ]);

        self::assertSame(1, $result['data']['version']);
        self::assertFalse($result['data']['controls']['autoRotate']);
        self::assertSame('#111111', $result['data']['environment']['backgroundColor']);
        self::assertIsString($result['json']);
    }

    public function test_viewer_configuration_rejects_script_payload(): void
    {
        $validator = new Product3DViewerConfigurationValidator();
        $this->expectException(InvalidProductValue::class);
        $validator->validate([
            'version' => 1,
            'environment' => ['backgroundColor' => '<script>alert(1)</script>'],
        ]);
    }

    public function test_viewer_configuration_strips_unknown_fields(): void
    {
        $validator = new Product3DViewerConfigurationValidator();
        $result = $validator->validate([
            'version' => 1,
            'unexpectedRoot' => 'drop-me',
            'controls' => ['autoRotate' => false, 'evilHook' => 'x'],
        ]);

        self::assertArrayNotHasKey('unexpectedRoot', $result['data']);
        self::assertFalse($result['data']['controls']['autoRotate']);
        self::assertArrayNotHasKey('evilHook', $result['data']['controls']);
    }

    public function test_viewer_configuration_rejects_shader_field_name(): void
    {
        $validator = new Product3DViewerConfigurationValidator();
        $this->expectException(InvalidProductValue::class);
        $validator->validate([
            'version' => 1,
            'shaderSource' => 'void main(){}',
        ]);
    }

    public function test_cable_spec_mapping_resolves_codes_not_ids(): void
    {
        $mapping = new Cable3DSpecificationMapping();
        $resolved = $mapping->resolve([
            ['code' => 'NOM-CABLE-OD', 'displayValue' => '28.4 mm'],
            ['code' => 'CORES', 'displayValue' => '4'],
            ['code' => 'NOM-COND-OD', 'displayValue' => '5.2'],
            ['code' => 'NOM-INS-OD', 'displayValue' => '7.4'],
            ['code' => 'CSA', 'displayValue' => '16'],
        ]);

        self::assertSame(28.4, $resolved['overallDiameterMm']);
        self::assertSame(4, $resolved['conductorCount']);
        self::assertSame(16.0, $resolved['conductorCsaMm2']);
        self::assertNotContains('overallDiameterMm', $resolved['missing']);
        self::assertNotContains('coreCount', $resolved['missing']);
        self::assertSame('NOM-CABLE-OD', $resolved['resolved']['overallDiameterMm']);
        self::assertSame('detailed', $resolved['detailLevel']);
        self::assertTrue($resolved['canGenerate']);
        self::assertStringStartsWith('fnv1a32:', $resolved['sourceHash']);
    }

    public function test_cable_spec_mapping_reports_missing_required_values(): void
    {
        $mapping = new Cable3DSpecificationMapping();
        $resolved = $mapping->resolve([
            ['code' => 'VOLTAGE', 'displayValue' => '0.6/1 kV'],
        ]);

        self::assertNull($resolved['overallDiameterMm']);
        self::assertNull($resolved['conductorCount']);
        self::assertSame('none', $resolved['detailLevel']);
        self::assertFalse($resolved['canGenerate']);
        self::assertContains('overallDiameterMm', $resolved['missing']);
    }

    public function test_cable_spec_mapping_parses_size_and_derives_insulation(): void
    {
        $mapping = new Cable3DSpecificationMapping();
        $resolved = $mapping->resolve([
            ['code' => 'NOM-CABLE-OD', 'value' => 28, 'unit' => 'mm', 'source' => 'variant'],
            ['code' => 'SIZE', 'displayValue' => '4C 16mm²', 'source' => 'variant'],
            ['code' => 'NOM-COND-OD', 'value' => 5, 'unit' => 'mm', 'source' => 'variant'],
            ['code' => 'NOM-INS-OD', 'value' => 7, 'unit' => 'mm', 'source' => 'variant'],
            ['code' => 'DEFAULT-COLOUR', 'displayValue' => 'Black', 'source' => 'product'],
        ]);

        self::assertSame(4, $resolved['conductorCount']);
        self::assertSame('detailed', $resolved['detailLevel']);
        self::assertSame('derived', $resolved['values']['insulationThicknessMm']['source']);
        self::assertEqualsWithDelta(1.0, $resolved['values']['insulationThicknessMm']['value'], 0.0001);
    }

    public function test_generation_metadata_allows_accessory_keys_and_still_rejects_script_content(): void
    {
        $validator = new \TriCab\Core\Product\Domain\Service\Product3DGenerationMetadataValidator();
        $result = $validator->validate([
            'version' => 1,
            'family' => 'accessory',
            'accuracy' => ['class' => 'visual-preview'],
            'assumptions' => ['generic'],
            'dimensionsUsed' => ['WIDTH'],
            'accessoryDefinition' => ['kind' => 'lug'],
            'unknownKey' => 'dropped',
        ]);

        self::assertSame('visual-preview', $result['data']['accuracy']['class']);
        self::assertSame(['WIDTH'], $result['data']['dimensionsUsed']);
        self::assertArrayNotHasKey('unknownKey', $result['data']);

        $this->expectException(InvalidProductValue::class);
        $validator->validate(['accuracy' => ['class' => 'x'], 'assumptions' => ['uses a shader']]);
    }
}
