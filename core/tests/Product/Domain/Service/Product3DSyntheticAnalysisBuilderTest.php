<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Service\Product3DSyntheticAnalysisBuilder;

final class Product3DSyntheticAnalysisBuilderTest extends TestCase
{
    public function test_small_file_is_not_required(): void
    {
        $builder = new Product3DSyntheticAnalysisBuilder();
        $analysis = $builder->build(1024);

        self::assertSame('not_required', $analysis['recommendation']);
        self::assertTrue($analysis['synthetic']);
        self::assertSame(1024, $analysis['fileSizeBytes']);
    }

    public function test_large_file_is_recommended(): void
    {
        $builder = new Product3DSyntheticAnalysisBuilder();
        $analysis = $builder->build(200_000);

        self::assertSame('recommended', $analysis['recommendation']);
    }
}
