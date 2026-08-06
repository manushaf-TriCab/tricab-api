<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class ProductRowVersionMapperTest extends TestCase
{
    public function test_to_token_reads_eight_byte_binary(): void
    {
        $binary = hex2bin('0000000000000001');
        self::assertNotFalse($binary);

        $token = ProductRowVersionMapper::toToken($binary);

        self::assertSame(base64_encode($binary), $token->toBase64());
    }

    public function test_to_token_reads_sixteen_character_hex_rowversion(): void
    {
        $token = ProductRowVersionMapper::toToken('abcdef0123456789');

        self::assertInstanceOf(RowVersionToken::class, $token);
        self::assertSame(8, strlen($token->toBinary()));
    }

    public function test_to_sql_binary_round_trips_through_token(): void
    {
        $token = RowVersionToken::fromBase64('AQIDBAUGBwg=');
        $sqlBinary = ProductRowVersionMapper::toSqlBinary($token);

        self::assertSame($token->toBinary(), $sqlBinary->bytes);
    }

    public function test_to_token_rejects_invalid_payload(): void
    {
        $this->expectException(RuntimeException::class);

        ProductRowVersionMapper::toToken('short');
    }
}
