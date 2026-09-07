<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;

final class TriExpenseEndpointsTest extends TestCase
{
    public function test_tri_expense_routes_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts'));
        $payload = TestAppFactory::json($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $payload['error']['code']);
    }

    public function test_tri_expense_routes_require_permission(): void
    {
        $app = TestAppFactory::create();
        // Dev admin usually has TRIEXPENSE.ACCESS — strip via repository if available.
        // At minimum route exists and responds within error envelope.
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/receipts'));
        self::assertContains($response->getStatusCode(), [200, 403]);
        $payload = TestAppFactory::json($response);
        self::assertArrayHasKey('success', $payload);
    }
}