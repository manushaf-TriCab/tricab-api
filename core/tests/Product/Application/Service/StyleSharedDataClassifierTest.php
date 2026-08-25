<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Service\StyleSharedDataClassifier;

final class StyleSharedDataClassifierTest extends TestCase
{
    private StyleSharedDataClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new StyleSharedDataClassifier();
    }

    public function testExactSharedWhenAllSiblingsAgree(): void
    {
        $result = $this->classifier->classifySpecification('VOLTAGE', [
            'BVA' => '1.8/3 kV',
            'BVC' => '1.8/3 kV',
            'BVM' => '1.8/3 kV',
            'BVP' => '1.8/3 kV',
        ]);
        self::assertSame(StyleSharedDataClassifier::EXACT_SHARED, $result['decision']);
    }

    public function testSinglePopulatedChildIsSeedCandidateNotExactShared(): void
    {
        $result = $this->classifier->classifySpecification('VOLTAGE', [
            'BVA' => '1.8/3 kV',
            'BVC' => null,
            'BVM' => null,
            'BVP' => null,
        ]);
        self::assertSame(StyleSharedDataClassifier::SEED_CANDIDATE, $result['decision']);
        self::assertSame('BVA', $result['seedStyle']);
    }

    public function testMajoritySharedWhenMostAgreeButOneDiffers(): void
    {
        $result = $this->classifier->classifySpecification('TEMPERATURE', [
            'BVA' => '90',
            'BVC' => '90',
            'BVM' => '105',
            'BVP' => '90',
        ]);
        self::assertSame(StyleSharedDataClassifier::MAJORITY_SHARED, $result['decision']);
    }

    public function testConflictWhenAllDifferent(): void
    {
        $result = $this->classifier->classifySpecification('COLOUR', [
            'BVA' => 'Red',
            'BVC' => 'Blue',
            'BVM' => 'Green',
            'BVP' => 'Yellow',
        ]);
        self::assertSame(StyleSharedDataClassifier::CHILD_SPECIFIC, $result['decision']);
    }

    public function testSharedTechnicalImageAcrossAllChildren(): void
    {
        $assets = $this->classifier->classifyMediaAssets([
            'BVA' => [['storagePath' => '/blob/cross-section.jpg', 'roleCode' => 'gallery']],
            'BVC' => [['storagePath' => '/blob/cross-section.jpg', 'roleCode' => 'gallery']],
            'BVM' => [['storagePath' => '/blob/cross-section.jpg', 'roleCode' => 'gallery']],
            'BVP' => [['storagePath' => '/blob/cross-section.jpg', 'roleCode' => 'gallery']],
        ]);
        self::assertSame(StyleSharedDataClassifier::EXACT_SHARED_ASSET, $assets[0]['decision']);
    }

    public function testHeroFromOneChildIsSeedCandidate(): void
    {
        $assets = $this->classifier->classifyMediaAssets([
            'BVA' => [['storagePath' => '/blob/hero-bva.jpg', 'roleCode' => 'hero']],
            'BVC' => [],
            'BVM' => [],
            'BVP' => [],
        ]);
        self::assertSame(StyleSharedDataClassifier::SEED_CANDIDATE, $assets[0]['decision']);
    }

    public function testMarketIntersectionNotUnion(): void
    {
        $result = $this->classifier->classifyMarkets([
            'BVA' => ['AU', 'NZ', 'US'],
            'BVC' => ['AU', 'NZ'],
            'BVM' => ['AU', 'NZ', 'US'],
            'BVP' => ['AU'],
        ]);
        self::assertSame(StyleSharedDataClassifier::MARKET_INTERSECTION, $result['decision']);
        self::assertSame(['AU'], $result['intersection']);
    }

    public function testIncompleteMarketData(): void
    {
        $result = $this->classifier->classifyMarkets([
            'BVA' => ['AU', 'NZ', 'US'],
            'BVC' => [],
            'BVM' => [],
            'BVP' => [],
        ]);
        self::assertSame(StyleSharedDataClassifier::MARKET_DATA_INCOMPLETE, $result['decision']);
    }
}
