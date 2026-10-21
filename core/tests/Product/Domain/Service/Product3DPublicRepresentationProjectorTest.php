<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Service\Product3DPublicRepresentationProjector;

final class Product3DPublicRepresentationProjectorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'representation' => [
                'scope' => 'product',
                'sourceVariantPublicId' => '0b1f5c1e-1111-4222-8333-444455556666',
                'sourceVariantCode' => 'A06-AMXX/6.5H2.5',
            ],
            'accuracy' => ['class' => 'visual-preview'],
            'sourceHash' => 'fnv1a32:abcd',
            'dimensionsUsed' => [
                'overallLength' => ['label' => 'Overall length', 'valueMm' => 21.8, 'definitionCode' => 'OVERALL-LENGTH', 'source' => 'variant', 'semantics' => 's'],
                'width' => ['label' => 'Width', 'valueMm' => 9.12345],
            ],
            'assumptions' => [['key' => 'plateThickness', 'label' => 'Plate thickness', 'valueMm' => 0.8, 'basis' => 'x']],
        ];
    }

    public function test_null_for_variant_level_and_missing_parts(): void
    {
        $p = new Product3DPublicRepresentationProjector();
        self::assertNull($p->project($this->metadata(), false));
        self::assertNull($p->project(null, true));
        foreach (['representation', 'accuracy', 'dimensionsUsed'] as $key) {
            $m = $this->metadata();
            unset($m[$key]);
            self::assertNull($p->project($m, true), $key);
        }
        $m = $this->metadata();
        $m['dimensionsUsed'] = [];
        self::assertNull($p->project($m, true));
        $m = $this->metadata();
        $m['accuracy']['class'] = 'unknown';
        self::assertNull($p->project($m, true));
        $m = $this->metadata();
        $m['representation']['scope'] = 'variant';
        self::assertNull($p->project($m, true));
    }

    public function test_projects_expected_shape_in_order(): void
    {
        $out = (new Product3DPublicRepresentationProjector())->project($this->metadata(), true);
        self::assertSame([
            'scope' => 'product',
            'accuracyClass' => 'visual-preview',
            'sourceVariantCode' => 'A06-AMXX/6.5H2.5',
            'dimensions' => [
                ['label' => 'Overall length', 'value' => 21.8, 'unit' => 'mm'],
                ['label' => 'Width', 'value' => 9.123, 'unit' => 'mm'],
            ],
            'illustrative' => [['label' => 'Plate thickness', 'value' => 0.8, 'unit' => 'mm']],
        ], $out);
    }

    public function test_sanitises_and_skips_bad_entries(): void
    {
        $m = $this->metadata();
        $m['representation']['sourceVariantCode'] = "AB\x00C" . str_repeat('x', 100);
        $m['dimensionsUsed'] = [
            ['label' => "<b>Bold</b>\x07 name", 'valueMm' => 5],
            ['label' => '', 'valueMm' => 5],
            ['valueMm' => 5],
            ['label' => 'Zero', 'valueMm' => 0],
            ['label' => 'Neg', 'valueMm' => -1],
            ['label' => 'Inf', 'valueMm' => INF],
            ['label' => 'NaN', 'valueMm' => NAN],
            ['label' => 'Str', 'valueMm' => 'abc'],
            ['label' => str_repeat('L', 100), 'valueMm' => 1.0],
            'scalar',
        ];
        $out = (new Product3DPublicRepresentationProjector())->project($m, true);
        self::assertNotNull($out);
        self::assertSame(80, mb_strlen((string) $out['sourceVariantCode']));
        self::assertStringStartsWith('ABCxxx', (string) $out['sourceVariantCode']);
        self::assertCount(2, $out['dimensions']);
        self::assertSame('Bold name', $out['dimensions'][0]['label']);
        self::assertSame(60, mb_strlen($out['dimensions'][1]['label']));
    }

    public function test_no_forbidden_keys_or_values_leak(): void
    {
        $json = (string) json_encode((new Product3DPublicRepresentationProjector())->project($this->metadata(), true));
        foreach (['definitionCode', 'OVERALL-LENGTH', 'sourceHash', 'fnv1a32', 'sourceVariantPublicId', '0b1f5c1e', 'semantics', '"source"', 'basis', 'storage'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/', $json);
    }
}
