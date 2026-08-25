<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Service\EffectiveStyleSnapshotBuilder;

final class EffectiveStyleSnapshotBuilderTest extends TestCase
{
    private EffectiveStyleSnapshotBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new EffectiveStyleSnapshotBuilder();
    }

    public function testBvToBvaInheritanceWithChildOverride(): void
    {
        $effective = $this->builder->buildEffectiveSpecifications(
            [
                ['styleCode' => 'BV', 'level' => 1, 'specs' => ['VOLTAGE' => '1.8/3 kV', 'CONDUCTOR' => 'Copper']],
                ['styleCode' => 'BVA', 'level' => 0, 'specs' => []],
            ],
            ['TEMPERATURE' => '90°C'],
        );
        $byCode = [];
        foreach ($effective as $row) {
            $byCode[$row['definitionCode']] = $row;
        }
        self::assertTrue($byCode['VOLTAGE']['inherited']);
        self::assertSame('BV', $byCode['VOLTAGE']['sourceStyleCode']);
        self::assertFalse($byCode['TEMPERATURE']['inherited']);
        self::assertSame('BVA', $byCode['TEMPERATURE']['sourceStyleCode']);
    }

    public function testChildOverrideWinsOverParent(): void
    {
        $effective = $this->builder->buildEffectiveSpecifications(
            [
                ['styleCode' => 'BV', 'level' => 1, 'specs' => ['TEMPERATURE' => '90°C']],
                ['styleCode' => 'BVM', 'level' => 0, 'specs' => []],
            ],
            ['TEMPERATURE' => '105°C'],
        );
        self::assertSame('105°C', $effective[0]['effectiveValue']);
        self::assertFalse($effective[0]['inherited']);
    }

    public function testParentChangeInvalidatesAllDescendants(): void
    {
        $targets = $this->builder->invalidationTargets('BV', ['BVA', 'BVC', 'BVM', 'BVP']);
        self::assertSame(['BV', 'BVA', 'BVC', 'BVM', 'BVP'], $targets);
    }

    public function testChildChangeOnlyInvalidatesSelfAndDescendants(): void
    {
        $targets = $this->builder->invalidationTargets('BVA', []);
        self::assertSame(['BVA'], $targets);
    }

    public function testRebuildIsDeterministic(): void
    {
        $chain = [
            ['styleCode' => 'BV', 'level' => 1, 'specs' => ['VOLTAGE' => '1.8/3 kV']],
            ['styleCode' => 'BVA', 'level' => 0, 'specs' => []],
        ];
        $first = $this->builder->buildEffectiveSpecifications($chain, []);
        $second = $this->builder->buildEffectiveSpecifications($chain, []);
        self::assertSame($first, $second);
    }
}
