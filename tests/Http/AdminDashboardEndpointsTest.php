<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;

final class AdminDashboardEndpointsTest extends TestCase
{
    public function test_dashboard_requires_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/dashboard'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
    }

    public function test_dashboard_returns_aggregate_shape(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/dashboard'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('productStatus', $data);
        self::assertArrayHasKey('catalogueHealth', $data);
        self::assertArrayHasKey('attention', $data);
        self::assertArrayHasKey('recentProducts', $data);
        self::assertArrayHasKey('recentActivity', $data);
        self::assertArrayHasKey('recentUsers', $data);
        self::assertArrayHasKey('recentContacts', $data);
        self::assertArrayHasKey('productActivity', $data);
        self::assertArrayHasKey('capabilities', $data);

        /** @var array<string, mixed> $activity */
        $activity = $data['productActivity'];
        self::assertSame('30d', $activity['range']);
        self::assertIsArray($activity['points']);
    }

    public function test_product_activity_range_query(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/dashboard/product-activity?range=7d'),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('7d', $data['range']);
        self::assertIsArray($data['points']);
        self::assertGreaterThanOrEqual(6, count($data['points']));
        self::assertLessThanOrEqual(8, count($data['points']));
    }
}
