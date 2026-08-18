<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;

final class ExternalSubjectIdTest extends TestCase
{
    public function test_accepts_and_trims_subject(): void
    {
        $subject = ExternalSubjectId::fromString('  abc-123-oid  ');

        self::assertSame('abc-123-oid', $subject->toString());
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_blank_subjects(string $value): void
    {
        $this->expectException(InvalidIdentityValue::class);

        ExternalSubjectId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    public function test_equality(): void
    {
        $a = ExternalSubjectId::fromString('subject-1');
        $b = ExternalSubjectId::fromString(' subject-1 ');
        $c = ExternalSubjectId::fromString('subject-2');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
