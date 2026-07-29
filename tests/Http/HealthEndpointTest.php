<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;

final class HealthEndpointTest extends TestCase
{
    public function test_health_returns_healthy_payload(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/health'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame('tricab-api', $payload['service']);
        self::assertSame('healthy', $payload['status']);
    }
}
