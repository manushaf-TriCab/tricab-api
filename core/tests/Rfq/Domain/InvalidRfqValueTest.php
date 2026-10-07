<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/** A refusal for input that gave no lines carries the parser's reason. */
final class InvalidRfqValueTest extends TestCase
{
    public function test_nothing_read_says_why_from_the_parser_notes(): void
    {
        self::assertSame(
            'Nothing in the files could be read as an RFQ line. scan.jpg is an image; no image reader is switched on, so it was not read.',
            InvalidRfqValue::nothingRead('files', ['scan.jpg is an image; no image reader is switched on, so it was not read.'])->getMessage(),
        );
    }

    public function test_nothing_read_without_notes_is_one_sentence(): void
    {
        self::assertSame('Nothing in the text could be read as an RFQ line.', InvalidRfqValue::nothingRead('text', [' ', ''])->getMessage());
    }
}
