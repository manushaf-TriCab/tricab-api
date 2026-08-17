<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\VerifiedGoogleIdToken;
use TriCab\Core\Identity\Contract\GoogleIdTokenValidator;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class GoogleAuthEndpointsTest extends TestCase
{
    public function test_google_login_issues_session_for_registered_user(): void
    {
        $app = TestAppFactory::create([
            'GOOGLE_CLIENT_ID' => 'test-google-client.apps.googleusercontent.com',
        ]);
        $this->seedUser($app, 'admin@tricab.com');
        $this->bindGoogleValidator($app, new VerifiedGoogleIdToken(
            subjectId: 'google-sub-1',
            emailAddress: 'admin@tricab.com',
            emailVerified: true,
        ));

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/google', [
            'idToken' => 'aaa.bbb.ccc',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['accessToken']);
        self::assertSame('Bearer', $data['tokenType']);
        self::assertIsArray($data['user']);
        /** @var array<string, mixed> $user */
        $user = $data['user'];
        self::assertSame('admin@tricab.com', $user['emailAddress']);
        self::assertContains('ACCOUNT.LOGIN', $user['permissions']);
    }

    public function test_google_login_rejects_unregistered_user(): void
    {
        $app = TestAppFactory::create([
            'GOOGLE_CLIENT_ID' => 'test-google-client.apps.googleusercontent.com',
        ]);
        $this->bindGoogleValidator($app, new VerifiedGoogleIdToken(
            subjectId: 'google-sub-missing',
            emailAddress: 'unknown@example.com',
            emailVerified: true,
        ));

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/google', [
            'idToken' => 'aaa.bbb.ccc',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('USER_NOT_REGISTERED', $error['code']);
        self::assertSame(
            'Your Google account is not registered with TriCab.',
            $error['message'],
        );
    }

    public function test_google_login_requires_id_token(): void
    {
        $app = TestAppFactory::create([
            'GOOGLE_CLIENT_ID' => 'test-google-client.apps.googleusercontent.com',
        ]);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/google', [
            'email' => 'admin@tricab.com',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('AUTH_GOOGLE_TOKEN_INVALID', $error['code']);
    }

    public function test_google_route_absent_when_not_configured(): void
    {
        $app = TestAppFactory::create([
            'GOOGLE_CLIENT_ID' => '',
        ]);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/google', [
            'idToken' => 'aaa.bbb.ccc',
        ]));

        self::assertSame(404, $response->getStatusCode());
    }

    private function seedUser(App $app, string $email): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);

        $emailAddress = EmailAddress::fromString($email);
        $users->register(
            emailAddress: $emailAddress,
            displayName: 'Seed User',
            status: UserStatus::active(),
            roles: [RoleCode::fromString('ADMINISTRATOR')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('seed-' . $email),
            ),
        );
    }

    private function bindGoogleValidator(App $app, VerifiedGoogleIdToken $verified): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue(method_exists($container, 'set'));
        /** @var \DI\Container $container */
        $container->set(GoogleIdTokenValidator::class, new FakeGoogleIdTokenValidatorForHttpTest($verified));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}

final class FakeGoogleIdTokenValidatorForHttpTest implements GoogleIdTokenValidator
{
    public function __construct(
        private readonly VerifiedGoogleIdToken $verified,
    ) {
    }

    public function validate(string $idToken): VerifiedGoogleIdToken
    {
        unset($idToken);

        return $this->verified;
    }
}
