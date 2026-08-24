<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;

final class LegacyPricingMaskGeneratorTest extends TestCase
{
    public function test_cable_type_mask_rule(): void
    {
        $generator = new LegacyPricingMaskGenerator();

        self::assertSame(
            ['RRA-XXXD/10A16BK'],
            $generator->generateCandidates('RRA-CAXD/10A16BK'),
        );
        self::assertSame(
            ['BVA-XXXD/1C4BK'],
            $generator->generateCandidates('BVA-PAXD/1C4BK'),
        );
    }

    public function test_no_mask_for_existing_xxx_code(): void
    {
        $generator = new LegacyPricingMaskGenerator();
        self::assertSame([], $generator->generateCandidates('RRA-XXXD/10A16BK'));
    }
}
