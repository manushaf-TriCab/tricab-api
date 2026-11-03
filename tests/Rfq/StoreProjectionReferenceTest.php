<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Storage\StoreProjection;

final class StoreProjectionReferenceTest extends TestCase
{
    private const ID = '02742dbd000000000000000000000001';

    public function testNumberedReferenceIsPaddedToSixDigits(): void
    {
        self::assertSame('RFQ-000001', StoreProjection::reference(self::ID, 1));
        self::assertSame('RFQ-000042', StoreProjection::reference(self::ID, '42'));
        self::assertSame('RFQ-999999', StoreProjection::reference(self::ID, 999999));
    }

    public function testNumberPastSixDigitsIsNotTruncated(): void
    {
        self::assertSame('RFQ-1000000', StoreProjection::reference(self::ID, 1000000));
    }

    public function testFallsBackToTheOldHexReferenceWithoutANumber(): void
    {
        self::assertSame('RFQ-02742DBD', StoreProjection::reference(self::ID));
        self::assertSame('RFQ-02742DBD', StoreProjection::reference(self::ID, null));
        self::assertSame('RFQ-02742DBD', StoreProjection::reference(self::ID, 0));
        self::assertSame('RFQ-02742DBD', StoreProjection::hexReference(self::ID));
    }
}
