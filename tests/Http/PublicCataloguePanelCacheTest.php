<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Infrastructure\Cache\ProcessMemoryCache;
use TriCab\Api\Tests\TestAppFactory;

final class PublicCataloguePanelCacheTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessMemoryCache::clearAll();
    }

    protected function tearDown(): void
    {
        ProcessMemoryCache::clearAll();
    }

    public function test_panel_sets_etag_and_returns_304_on_if_none_match(): void
    {
        $app = TestAppFactory::create([
            'APP_PERFORMANCE_LOGGING' => 'false',
        ]);

        $first = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogue/panel'));
        self::assertSame(200, $first->getStatusCode());
        $etag = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        self::assertStringContainsString('max-age=60', $first->getHeaderLine('Cache-Control'));

        $second = $app->handle(
            TestAppFactory::request('GET', '/api/v1/catalogue/panel')
                ->withHeader('If-None-Match', $etag),
        );
        self::assertSame(304, $second->getStatusCode());
    }

    public function test_lookups_are_cached_in_process(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
            'APP_PERFORMANCE_LOGGING' => 'false',
        ]);

        $request = TestAppFactory::request('GET', '/api/v1/admin/product-lookups')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, 'development-tenant')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'development-thilan')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, 'thilanh@tricab.com');

        $first = $app->handle($request);
        self::assertSame(200, $first->getStatusCode());
        $payload = TestAppFactory::json($first);
        self::assertTrue($payload['success']);

        $second = $app->handle($request);
        self::assertSame(200, $second->getStatusCode());
        self::assertSame($payload, TestAppFactory::json($second));
        self::assertSame('private, max-age=0, must-revalidate', $second->getHeaderLine('Cache-Control'));
    }
}
