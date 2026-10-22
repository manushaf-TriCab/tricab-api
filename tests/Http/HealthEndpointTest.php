<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use TriCab\Api\Config\ReleaseRevision;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Response\JsonResponder;
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
        self::assertArrayNotHasKey('cache', $payload);
        self::assertArrayNotHasKey('database', $payload);
        self::assertArrayNotHasKey('apcu', $payload);
        // A checkout has no release.json, so there is no revision to report.
        self::assertArrayNotHasKey('revision', $payload);
    }

    public function test_a_release_reports_the_commits_it_was_built_from(): void
    {
        $settings = TestAppFactory::create()->getContainer()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);
        $action = new HealthAction($settings, new JsonResponder(), new ReleaseRevision('b2a3545a', '9f1c2d3e'));

        $response = $action(TestAppFactory::request('GET', '/health'), new Response());
        $payload = TestAppFactory::json($response);

        self::assertSame('healthy', $payload['status']);
        self::assertSame(['api' => 'b2a3545a', 'web' => '9f1c2d3e'], $payload['revision']);
    }
}
