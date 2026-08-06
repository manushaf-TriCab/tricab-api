<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use RuntimeException;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Maps SQL Server rowversion (8 bytes) to/from RowVersionToken (Base64).
 */
final class ProductRowVersionMapper
{
    public static function toToken(mixed $value): RowVersionToken
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new RuntimeException('Unable to read RowVersion stream.');
            }
            $value = $contents;
        }

        if (!is_string($value)) {
            throw new RuntimeException('Expected RowVersion binary string.');
        }

        if (strlen($value) === 8) {
            return RowVersionToken::fromBinary($value);
        }

        $hex = strtolower(ltrim($value, "0x\x00"));
        // Some drivers return 16 hex chars for 8 bytes.
        if (strlen($hex) === 16 && ctype_xdigit($hex)) {
            $binary = hex2bin($hex);
            if ($binary === false || strlen($binary) !== 8) {
                throw new RuntimeException('Unable to decode RowVersion hex.');
            }

            return RowVersionToken::fromBinary($binary);
        }

        // Accept Base64 already (16 chars typical for 8 bytes).
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $value) === 1 && strlen(base64_decode($value, true) ?: '') === 8) {
            return RowVersionToken::fromBase64($value);
        }

        throw new RuntimeException(sprintf('Unexpected RowVersion payload length %d.', strlen($value)));
    }

    public static function toSqlBinary(RowVersionToken $token): SqlBinary
    {
        return new SqlBinary($token->toBinary());
    }
}
