<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;

final class CorsMiddlewareTest extends TestCase
{
    public function test_pricing_preflight_allows_pricing_context_token_header(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(
            TestAppFactory::request('OPTIONS', '/api/v1/products/flexible-control-lszh-3d-test/variants/pricing')
                ->withHeader('Origin', 'http://localhost:5173')
                ->withHeader('Access-Control-Request-Method', 'POST')
                ->withHeader(
                    'Access-Control-Request-Headers',
                    'authorization,content-type,x-pricing-context-token',
                ),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost:5173', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString(
            'X-Pricing-Context-Token',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
    }
}
