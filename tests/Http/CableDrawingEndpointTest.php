<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TriCab\Api\Tests\TestAppFactory;

final class CableDrawingEndpointTest extends TestCase
{
    public function test_returns_the_cross_section_as_svg_without_sign_in(): void
    {
        $response = TestAppFactory::create()->handle(
            TestAppFactory::request('GET', '/api/v1/cable-drawings')->withQueryParams(['code' => 'CA-PAXN/5C16BK']),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/svg+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('CA 4C16+1E6', $response->getHeaderLine('X-Cable-Spec'));
        self::assertStringContainsString('public', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('<svg', (string) $response->getBody());
        self::assertSame('approximate', $response->getHeaderLine('X-Cable-Drawing-Accuracy'));
        self::assertNotEmpty(json_decode($response->getHeaderLine('X-Cable-Drawing-Warnings'), true));
    }

    public function test_requires_a_code(): void
    {
        $response = TestAppFactory::create()->handle(TestAppFactory::request('GET', '/api/v1/cable-drawings'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('CABLE_CODE_REQUIRED', self::errorCode($response));
    }

    public function test_reports_a_code_with_no_spec_as_not_found(): void
    {
        $response = TestAppFactory::create()->handle(
            TestAppFactory::request('GET', '/api/v1/cable-drawings')->withQueryParams(['code' => 'CA-PAXN/9C16BK']),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('CABLE_SPEC_NOT_FOUND', self::errorCode($response));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    private static function errorCode(ResponseInterface $response): mixed
    {
        $error = TestAppFactory::json($response)['error'] ?? null;
        self::assertIsArray($error);

        return $error['code'] ?? null;
    }
}
