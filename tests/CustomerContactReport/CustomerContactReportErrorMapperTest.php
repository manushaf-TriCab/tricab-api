<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Tests\TestAppFactory;

final class CustomerContactReportErrorMapperTest extends TestCase
{
    public function test_database_failures_do_not_leak_sql(): void
    {
        $mapper = new CustomerContactReportErrorMapper(new JsonResponder());

        $temporary = $mapper->map(DatabaseTemporarilyUnavailable::afterConnectTimeout(
            '40613',
            new RuntimeException('SQLSTATE[HY000]: SELECT * FROM [cc_report].[Reports]'),
        ));
        $permanent = $mapper->map(new DatabaseUnavailable('Login failed for user sa; SELECT secret'));
        $unknown = $mapper->map(new RuntimeException('Conversion failed when converting nvarchar value in [cc_report].[Reports]'));

        $temporaryBody = TestAppFactory::json($temporary);
        $permanentBody = TestAppFactory::json($permanent);
        $unknownBody = TestAppFactory::json($unknown);
        $temporaryError = $this->record($temporaryBody['error'] ?? null);
        $permanentError = $this->record($permanentBody['error'] ?? null);
        $unknownError = $this->record($unknownBody['error'] ?? null);

        self::assertSame(503, $temporary->getStatusCode());
        self::assertSame('DATABASE_TEMPORARILY_UNAVAILABLE', $temporaryError['code'] ?? null);
        self::assertSame(503, $permanent->getStatusCode());
        self::assertSame('DATABASE_UNAVAILABLE', $permanentError['code'] ?? null);
        self::assertSame(500, $unknown->getStatusCode());
        self::assertSame('INTERNAL_ERROR', $unknownError['code'] ?? null);

        $encoded = json_encode([$temporaryBody, $permanentBody, $unknownBody], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('cc_report', $encoded);
        self::assertStringNotContainsString('SELECT', $encoded);
        self::assertStringNotContainsString('40613', $encoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a JSON object.');
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected string keys.');
            }
            $record[$key] = $item;
        }

        return $record;
    }
}
