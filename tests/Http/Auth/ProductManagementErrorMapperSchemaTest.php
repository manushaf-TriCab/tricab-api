<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http\Auth;

use PHPUnit\Framework\TestCase;
use PDOException;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;

final class ProductManagementErrorMapperSchemaTest extends TestCase
{
    public function test_invalid_column_for_pricing_uom_is_not_region_migration_message(): void
    {
        $pdo = new PDOException(
            "SQLSTATE[42S22]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid column name 'SpecificationUnitId'.",
            0,
        );
        $pdo->errorInfo = ['42S22', 207, "Invalid column name 'SpecificationUnitId'."];

        $response = (new ProductManagementErrorMapper(new JsonResponder()))->map(
            DatabaseUnavailable::fromThrowable($pdo),
        );

        self::assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('DATABASE_SCHEMA_MISMATCH', $payload['error']['code']);
        self::assertStringNotContainsString('region migration', strtolower($payload['error']['message']));
        self::assertStringContainsString('required table or column', $payload['error']['message']);
    }

    public function test_invalid_region_object_still_uses_region_migration_message(): void
    {
        $pdo = new PDOException(
            "SQLSTATE[42S02]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name 'product.Regions'.",
            0,
        );
        $pdo->errorInfo = ['42S02', 208, "Invalid object name 'product.Regions'."];

        $response = (new ProductManagementErrorMapper(new JsonResponder()))->map(
            DatabaseUnavailable::fromThrowable($pdo),
        );

        self::assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('DATABASE_SCHEMA_MISMATCH', $payload['error']['code']);
        self::assertStringContainsString('region', strtolower($payload['error']['message']));
    }
}
