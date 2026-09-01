<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

final class ThumbnailBackgroundColorTest extends TestCase
{
    public function testNormalizesValidHexToUppercase(): void
    {
        $color = ThumbnailBackgroundColor::fromString('#e8e8e7');

        self::assertSame('#E8E8E7', $color->toString());
    }

    public function testRejectsInvalidValues(): void
    {
        $this->expectException(InvalidProductValue::class);
        ThumbnailBackgroundColor::fromString('red');
    }

    public function testTryFromStringReturnsNullForInvalid(): void
    {
        self::assertNull(ThumbnailBackgroundColor::tryFromString('#abc'));
        self::assertNull(ThumbnailBackgroundColor::tryFromString(null));
    }
}
