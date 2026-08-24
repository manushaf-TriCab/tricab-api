<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Readiness;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Readiness\MigrationReadinessStatus;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;

final class StyleCodeNormalizerTest extends TestCase
{
    private StyleCodeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new StyleCodeNormalizer();
    }

    public function testExactStyleReady(): void
    {
        $result = $this->normalizer->normalize('  ft ');
        self::assertSame(MigrationReadinessStatus::READY, $result['status']);
        self::assertSame('FT', $result['normalized']);
        self::assertFalse($result['isCommaSeparated']);
    }

    public function testCommaStyleReview(): void
    {
        $result = $this->normalizer->normalize('B3C, B3M');
        self::assertSame(MigrationReadinessStatus::REVIEW, $result['status']);
        self::assertNull($result['normalized']);
        self::assertTrue($result['isCommaSeparated']);
        self::assertContains('comma_or_multi_style_code', $result['issues']);
    }

    public function testInvalidBlank(): void
    {
        $result = $this->normalizer->normalize('   ');
        self::assertSame(MigrationReadinessStatus::INVALID, $result['status']);
        self::assertTrue($result['isBlank']);
    }

    public function testNumericLegacyReview(): void
    {
        $result = $this->normalizer->normalize('10');
        self::assertSame(MigrationReadinessStatus::REVIEW, $result['status']);
        self::assertTrue($result['isNumeric']);
        self::assertSame('10', $result['normalized']);
    }

    public function testHtmlEntitiesDecoded(): void
    {
        $result = $this->normalizer->normalize('A&amp;B');
        self::assertSame('A&B', $result['normalized']);
        self::assertSame(MigrationReadinessStatus::READY, $result['status']);
    }

    public function testReadinessStatusesAreExplicit(): void
    {
        self::assertContains(MigrationReadinessStatus::READY, MigrationReadinessStatus::all());
        self::assertContains(MigrationReadinessStatus::BLOCKED, MigrationReadinessStatus::all());
        self::assertContains(MigrationReadinessStatus::SOURCE_ONLY, MigrationReadinessStatus::all());
        self::assertFalse(MigrationReadinessStatus::isValid('ERROR'));
    }
}
