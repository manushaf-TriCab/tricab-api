<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Api\Http\Middleware\RequirePlatformAdministratorMiddleware;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class PlatformSettingsEndpointsTest extends TestCase
{
    public function test_admin_can_save_review_history_and_reset_with_version_check(): void
    {
        $app = TestAppFactory::create();
        $base = '/api/v1/admin/settings/platform';
        $initial = $app->handle(TestAppFactory::request('GET', $base));
        self::assertSame(200, $initial->getStatusCode());
        $data = $this->snapshot($initial);
        self::assertSame(0, $data['version']);
        self::assertSame([], $data['overrides']);

        $save = $app->handle($this->json('PUT', $base, [
            'expectedVersion' => 0,
            'overrides' => ['ai' => ['tasks' => ['rfqMailClassifierModel' => 'claude-sonnet-5-5']],
                'notifications' => ['nprMailTestRecipient' => 'review@example.com']],
        ]));
        self::assertSame(200, $save->getStatusCode());
        $saved = $this->snapshot($save);
        self::assertSame(1, $saved['version']);
        self::assertSame('claude-sonnet-5-5', $saved['sections']['ai']['tasks']['rfqMailClassifierModel']);
        self::assertSame('override', $saved['sources']['notifications']['nprMailTestRecipient']);

        $stale = $app->handle($this->json('PUT', $base, ['expectedVersion' => 0, 'overrides' => []]));
        self::assertSame(409, $stale->getStatusCode());
        $invalid = $app->handle($this->json('PUT', $base, ['expectedVersion' => 1, 'overrides' => ['notifications' => ['graphClientSecret' => 'bad']]]));
        self::assertSame(422, $invalid->getStatusCode());

        /** @var array{data: array{items: list<array{version: int}>}} $historyPayload */
        $historyPayload = TestAppFactory::json($app->handle(TestAppFactory::request('GET', $base . '/history')));
        $history = $historyPayload['data']['items'];
        self::assertCount(1, $history);
        self::assertSame(1, $history[0]['version']);
        $reset = $app->handle($this->json('POST', $base . '/reset', ['expectedVersion' => 1, 'section' => 'notifications']));
        self::assertSame(200, $reset->getStatusCode());
        $afterReset = $this->snapshot($reset);
        self::assertSame('claude-sonnet-5-5', $afterReset['sections']['ai']['tasks']['rfqMailClassifierModel']);
        self::assertSame([], $afterReset['overrides']['notifications'] ?? []);
        $restore = $app->handle($this->json('POST', $base . '/restore', ['expectedVersion' => 2, 'version' => 1]));
        self::assertSame(200, $restore->getStatusCode());
        self::assertSame('review@example.com', $this->snapshot($restore)['sections']['notifications']['nprMailTestRecipient']);
    }

    public function test_model_assignment_requires_catalog_compatibility(): void
    {
        $app = TestAppFactory::create();
        $base = '/api/v1/admin/settings/platform';
        $bad = $app->handle($this->json('PUT', $base, [
            'expectedVersion' => 0,
            'overrides' => ['ai' => ['tasks' => ['foundryModelDeployment' => 'openai/gpt-4o-mini']]],
        ]));
        self::assertSame(422, $bad->getStatusCode());
        $inheritedWithoutDefault = $app->handle($this->json('PUT', $base, [
            'expectedVersion' => 0,
            'overrides' => ['ai' => ['tasks' => ['rfqMailClassifierModel' => null]]],
        ]));
        self::assertSame(422, $inheritedWithoutDefault->getStatusCode());
    }

    public function test_product_permission_holder_without_platform_role_is_denied_on_every_route(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(1, ['ACCOUNT.LOGIN', 'PRODUCT.UPDATE', 'USER.UPDATE'], ['EMPLOYEE']);
        foreach ([
            ['GET', '/api/v1/admin/settings/platform', null],
            ['PUT', '/api/v1/admin/settings/platform', ['expectedVersion' => 0, 'overrides' => []]],
            ['GET', '/api/v1/admin/settings/platform/history', null],
            ['POST', '/api/v1/admin/settings/platform/reset', ['expectedVersion' => 0]],
            ['POST', '/api/v1/admin/settings/platform/restore', ['expectedVersion' => 0, 'version' => 1]],
        ] as [$method, $path, $body]) {
            $request = $body === null ? TestAppFactory::request($method, $path) : $this->json($method, $path, $body);
            $response = $app->handle($request);
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertSame('PLATFORM_ADMINISTRATOR_REQUIRED', $this->errorCode($response), $path);
        }
    }

    public function test_email_code_platform_administrator_is_denied(): void
    {
        $user = new AuthenticatedUser(1, '11111111-1111-4111-8111-111111111111', 'admin@example.com', 'Admin', 'ACTIVE', ['ADMINISTRATOR'], ['ACCOUNT.LOGIN']);
        $request = TestAppFactory::request('GET', '/api/v1/admin/settings/platform')
            ->withAttribute(AuthenticatedUserAttribute::KEY, $user)
            ->withAttribute(VerifiedIdentityAttribute::KEY, new VerifiedIdentity('email_otp', 'subject', 'tenant'));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Low-assurance request reached the action.');
            }
        };
        $response = (new RequirePlatformAdministratorMiddleware(new JsonResponder()))->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_AUTHENTICATION_ASSURANCE', $this->errorCode($response));
    }

    /**
     * @return array{
     *     version: int,
     *     overrides: array<string, mixed>,
     *     sections: array{ai: array{tasks: array<string, string|null>}, notifications: array<string, mixed>},
     *     sources: array{notifications: array<string, string>}
     * }
     */
    private function snapshot(ResponseInterface $response): array
    {
        /** @var array{data: array{version: int, overrides: array<string, mixed>, sections: array{ai: array{tasks: array<string, string|null>}, notifications: array<string, mixed>}, sources: array{notifications: array<string, string>}}} $payload */
        $payload = TestAppFactory::json($response);
        return $payload['data'];
    }

    private function errorCode(ResponseInterface $response): string
    {
        /** @var array{error: array{code: string}} $payload */
        $payload = TestAppFactory::json($response);
        return $payload['error']['code'];
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        return TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
