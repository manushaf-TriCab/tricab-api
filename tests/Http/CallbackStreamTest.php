<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Response\CallbackStream;

final class CallbackStreamTest extends TestCase
{
    public function test_runs_the_producer_once_and_reports_eof(): void
    {
        $runs = 0;
        $stream = new CallbackStream(static function (callable $write) use (&$runs): void {
            $runs++;
            $write('one ');
            $write('two');
        });
        self::assertFalse($stream->eof());
        self::assertSame('', (string) $stream, 'casting to a string never runs the producer');

        $seen = '';
        $stream->drain(static function (string $chunk) use (&$seen): void {
            $seen .= $chunk;
        });
        $stream->drain(static function (): void {
            self::fail('a second drain must not run the producer again');
        });

        self::assertSame('one two', $seen);
        self::assertSame(1, $runs);
        self::assertTrue($stream->eof());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
    }

    public function test_is_finished_even_when_the_producer_throws(): void
    {
        $stream = new CallbackStream(static function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            $stream->drain(static function (): void {
            });
        } catch (\RuntimeException) {
        }

        self::assertTrue($stream->eof(), 'Slim must not keep reading a body whose producer died');
    }
}
