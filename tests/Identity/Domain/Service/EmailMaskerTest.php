<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Service\EmailMasker;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

final class EmailMaskerTest extends TestCase
{
    public function test_masks_typical_local_part(): void
    {
        self::assertSame(
            'j***h@example.com',
            EmailMasker::mask(EmailAddress::fromString('john.smith@example.com')),
        );
    }

    public function test_masks_single_character_local_part(): void
    {
        self::assertSame(
            'a***@example.com',
            EmailMasker::mask(EmailAddress::fromString('a@example.com')),
        );
    }
}
