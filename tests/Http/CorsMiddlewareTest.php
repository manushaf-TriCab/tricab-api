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

    /**
     * Onboarding's assessment submission, policy acknowledgement and form
     * submission all send `Idempotency-Key`. Without it in the allow-list the
     * browser drops the request after the preflight, and the API never sees it.
     */
    public function test_onboarding_write_preflight_allows_idempotency_key_header(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(
            TestAppFactory::request('OPTIONS', '/api/v1/onboarding/training/950eff5c-c21b-4cac-8e85-05c94e3399ed/attempts/de1ad6ed-2bb7-f111-a6a6-000d3ae0746f')
                ->withHeader('Origin', 'http://localhost:5173')
                ->withHeader('Access-Control-Request-Method', 'POST')
                ->withHeader('Access-Control-Request-Headers', 'authorization,content-type,idempotency-key'),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost:5173', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString(
            'Idempotency-Key',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
    }
}
