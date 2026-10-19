<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware as DevAuth;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\SuccessfulLoginRecorder;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class LastLoginRecordingTest extends TestCase
{
    public function test_successful_guest_verification_records_login_but_unauthenticated_requests_do_not(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $sql = new FakeSqlConnection();
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-19T02:00:00+00:00');
            }
        };
        $container->set(SuccessfulLoginRecorder::class, new SuccessfulLoginRecorder($sql, $clock));
        self::assertSame(401, $app->handle(TestAppFactory::request('GET', '/api/v1/me'))->getStatusCode());
        self::assertCount(0, $sql->calls);
        $user = new AuthenticatedUser(1, '11111111-1111-4111-8111-111111111111', 'test@tricab.com', 'Test', 'active', [], []);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/me')->withAttribute(AuthenticatedUserAttribute::KEY, $user));
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $sql->calls);
        self::assertSame($user->publicId, $sql->calls[0]['params'][':publicId']);
        self::assertSame('2026-10-19T02:00:00+00:00', $sql->calls[0]['params'][':at']);
    }

    public function test_entra_resolution_records_only_successful_registered_identity(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $sql = new FakeSqlConnection();
        $container->set(SuccessfulLoginRecorder::class, new SuccessfulLoginRecorder($sql));
        $unknown = TestAppFactory::request('GET', '/api/v1/me')
            ->withHeader(DevAuth::HEADER_SUBJECT, 'not-registered')
            ->withHeader(DevAuth::HEADER_EMAIL, 'unknown@example.com');
        self::assertSame(403, $app->handle($unknown)->getStatusCode());
        self::assertCount(0, $sql->calls);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/me'))->getStatusCode());
        self::assertCount(1, $sql->calls);
    }

    public function test_timestamp_failure_does_not_deny_a_valid_sign_in(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $sql = new FakeSqlConnection();
        $sql->failNextWrite = new RuntimeException('database unavailable');
        $container->set(SuccessfulLoginRecorder::class, new SuccessfulLoginRecorder($sql));
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/me'))->getStatusCode());
        self::assertCount(1, $sql->calls);
    }
}
