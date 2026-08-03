<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

/**
 * Marks a parameter as raw binary for SQL Server PDO binding.
 * Prevents pdo_sqlsrv from translating binary payloads as UCS-2 strings.
 */
final class SqlBinary
{
    public function __construct(
        public readonly string $bytes,
    ) {
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }
}
