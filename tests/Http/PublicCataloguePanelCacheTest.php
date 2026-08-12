<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\TestAppFactory;

final class PublicCataloguePanelCacheTest extends TestCase
{
    public function test_panel_sets_etag_and_returns_304_on_if_none_match(): void
    {
        $app = TestAppFactory::create([
            'APP_PERFORMANCE_LOGGING' => 'false',
            'APP_CACHE_DRIVER' => 'array',
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

    public function test_lookups_are_cached_across_http_handles_on_same_container(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
            'APP_PERFORMANCE_LOGGING' => 'false',
            'APP_CACHE_DRIVER' => 'array',
        ]);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $metrics = $container->get(RequestPerformanceMetrics::class);
        self::assertInstanceOf(RequestPerformanceMetrics::class, $metrics);

        $request = TestAppFactory::request('GET', '/api/v1/admin/product-lookups')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, 'development-tenant')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'development-thilan')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, 'thilanh@tricab.com');

        $first = $app->handle($request);
        self::assertSame(200, $first->getStatusCode());
        $firstSnap = $metrics->snapshot();
        self::assertFalse($firstSnap['lookups_cache_hit']);

        $second = $app->handle($request);
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(TestAppFactory::json($first), TestAppFactory::json($second));
        self::assertSame('private, max-age=0, must-revalidate', $second->getHeaderLine('Cache-Control'));
        $secondSnap = $metrics->snapshot();
        self::assertTrue($secondSnap['lookups_cache_hit']);
        self::assertSame(0, $secondSnap['sql_count']);
        self::assertSame(0.0, $secondSnap['pdo_ms']);
        self::assertSame(0.0, $secondSnap['token_ms']);
    }

    public function test_panel_second_handle_is_cache_hit_without_pdo(): void
    {
        $app = TestAppFactory::create([
            'APP_CACHE_DRIVER' => 'array',
        ]);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $metrics = $container->get(RequestPerformanceMetrics::class);
        self::assertInstanceOf(RequestPerformanceMetrics::class, $metrics);

        $app->handle(TestAppFactory::request('GET', '/api/v1/catalogue/panel?includeProducts=false'));
        self::assertFalse($metrics->snapshot()['catalogue_cache_hit']);

        $second = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogue/panel?includeProducts=false'));
        self::assertSame(200, $second->getStatusCode());
        $snap = $metrics->snapshot();
        self::assertTrue($snap['catalogue_cache_hit']);
        self::assertSame(0, $snap['sql_count']);
        self::assertSame(0.0, $snap['pdo_ms']);
        self::assertSame(0.0, $snap['token_ms']);
    }
}
