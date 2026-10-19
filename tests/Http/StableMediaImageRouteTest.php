<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;

final class StableMediaImageRouteTest extends TestCase
{
    public function test_unknown_image_token_is_an_uncached_not_found_without_authentication(): void
    {
        $app = TestAppFactory::create(['APP_PERFORMANCE_LOGGING' => 'false']);

        foreach (['/api/v1/media/images/bogus', '/api/v1/media/images/bogus/cross-section.png'] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));

            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            $error = TestAppFactory::json($response)['error'] ?? null;
            self::assertIsArray($error);
            self::assertSame('MEDIA_NOT_FOUND', $error['code'] ?? null);
        }
    }
}
