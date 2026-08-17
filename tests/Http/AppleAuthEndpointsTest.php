<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\VerifiedAppleIdToken;
use TriCab\Core\Identity\Contract\AppleAuthorizationCodeExchanger;
use TriCab\Core\Identity\Contract\AppleIdTokenValidator;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class AppleAuthEndpointsTest extends TestCase
{
    public function test_challenge_returns_state_nonce_and_expiry(): void
    {
        $app = $this->createAppleApp();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/auth/apple/challenge'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['state']);
        self::assertIsString($data['nonce']);
        self::assertIsString($data['expiresAtUtc']);
        self::assertSame(2, substr_count((string) $data['state'], '.'));
    }

    public function test_apple_login_issues_session_without_external_identity_writes_and_me_works(): void
    {
        $app = $this->createAppleApp();
        $users = $this->users($app);
        $beforeLinks = $users->externalIdentityCount();
        $this->seedUser($app, 'admin@tricab.com');
        $afterSeedLinks = $users->externalIdentityCount();
        self::assertSame($beforeLinks + 1, $afterSeedLinks);

        $this->bindAppleFakes($app, new VerifiedAppleIdToken(
            subjectId: 'apple-sub-1',
            emailAddress: 'admin@tricab.com',
            emailVerified: true,
        ));

        $challenge = $this->challenge($app);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'authorizationCode' => 'single-use-code',
            'state' => $challenge['state'],
            'nonce' => $challenge['nonce'],
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
        self::assertSame($afterSeedLinks, $users->externalIdentityCount());
        self::assertNull($users->findByExternalIdentity(
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('admin@tricab.com')),
        ));

        $me = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')
                ->withHeader('Authorization', 'Bearer ' . $data['accessToken']),
        );
        $mePayload = TestAppFactory::json($me);

        self::assertSame(200, $me->getStatusCode());
        self::assertTrue($mePayload['success']);
        self::assertIsArray($mePayload['data']);
        /** @var array<string, mixed> $meUser */
        $meUser = $mePayload['data'];
        self::assertSame('admin@tricab.com', $meUser['emailAddress']);
        self::assertContains('ACCOUNT.LOGIN', $meUser['permissions']);
    }

    public function test_apple_login_rejects_unregistered_user(): void
    {
        $app = $this->createAppleApp();
        $this->bindAppleFakes($app, new VerifiedAppleIdToken(
            subjectId: 'apple-sub-missing',
            emailAddress: 'unknown@example.com',
            emailVerified: true,
        ));
        $challenge = $this->challenge($app);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'authorizationCode' => 'single-use-code',
            'state' => $challenge['state'],
            'nonce' => $challenge['nonce'],
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('USER_NOT_REGISTERED', $error['code']);
        self::assertSame(
            'This Apple account is not linked to a registered TriCab user.',
            $error['message'],
        );
    }

    public function test_apple_login_rejects_disabled_account(): void
    {
        $app = $this->createAppleApp();
        $this->seedUser($app, 'disabled@tricab.com', UserStatus::disabled());
        $this->bindAppleFakes($app, new VerifiedAppleIdToken(
            subjectId: 'apple-sub-disabled',
            emailAddress: 'disabled@tricab.com',
            emailVerified: true,
        ));
        $challenge = $this->challenge($app);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'authorizationCode' => 'single-use-code',
            'state' => $challenge['state'],
            'nonce' => $challenge['nonce'],
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('ACCOUNT_DISABLED', $error['code']);
    }

    public function test_apple_login_rejects_mismatched_nonce_against_state(): void
    {
        $app = $this->createAppleApp();
        $this->bindAppleFakes($app, new VerifiedAppleIdToken(
            subjectId: 'apple-sub-1',
            emailAddress: 'admin@tricab.com',
            emailVerified: true,
        ));
        $challenge = $this->challenge($app);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'authorizationCode' => 'single-use-code',
            'state' => $challenge['state'],
            'nonce' => 'not-the-challenge-nonce',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('AUTH_APPLE_NONCE_MISMATCH', $error['code']);
    }

    public function test_apple_login_rejects_invalid_state(): void
    {
        $app = $this->createAppleApp();
        $this->bindAppleFakes($app, new VerifiedAppleIdToken(
            subjectId: 'apple-sub-1',
            emailAddress: 'admin@tricab.com',
            emailVerified: true,
        ));

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'authorizationCode' => 'single-use-code',
            'state' => 'not.a.valid.jwt',
            'nonce' => 'nonce',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('AUTH_APPLE_STATE_INVALID', $error['code']);
    }

    public function test_apple_endpoint_rejects_email_only_body(): void
    {
        $app = $this->createAppleApp();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'email' => 'admin@tricab.com',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('AUTH_APPLE_CODE_REQUIRED', $error['code']);
    }

    public function test_apple_routes_absent_when_not_configured(): void
    {
        $app = TestAppFactory::create([
            'APPLE_CLIENT_ID' => '',
            'APPLE_TEAM_ID' => '',
            'APPLE_KEY_ID' => '',
            'APPLE_PRIVATE_KEY' => '',
            'APPLE_REDIRECT_URI' => '',
        ]);

        $challenge = $app->handle(TestAppFactory::request('GET', '/api/v1/auth/apple/challenge'));
        $login = $app->handle($this->jsonRequest('POST', '/api/v1/auth/apple', [
            'authorizationCode' => 'code',
            'state' => 'a.b.c',
            'nonce' => 'nonce',
        ]));

        self::assertSame(404, $challenge->getStatusCode());
        self::assertSame(404, $login->getStatusCode());
    }

    /**
     * @return App<\Psr\Container\ContainerInterface>
     */
    private function createAppleApp(): App
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem));

        return TestAppFactory::create([
            'APPLE_CLIENT_ID' => 'com.tricab.services',
            'APPLE_TEAM_ID' => 'TEAMID1234',
            'APPLE_KEY_ID' => 'KEYID12345',
            'APPLE_PRIVATE_KEY' => str_replace("\n", "\\n", $pem),
            'APPLE_REDIRECT_URI' => 'https://app.tricab.local/auth/apple/callback',
        ]);
    }

    /**
     * @return array{state: string, nonce: string, expiresAtUtc: string}
     */
    private function challenge(App $app): array
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/auth/apple/challenge'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload['data']);
        /** @var array{state: string, nonce: string, expiresAtUtc: string} $data */
        $data = $payload['data'];

        return $data;
    }

    private function seedUser(App $app, string $email, ?UserStatus $status = null): void
    {
        $users = $this->users($app);
        $emailAddress = EmailAddress::fromString($email);
        $users->register(
            emailAddress: $emailAddress,
            displayName: 'Seed User',
            status: $status ?? UserStatus::active(),
            roles: [RoleCode::fromString('ADMINISTRATOR')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: EmailOtpExternalIdentityFactory::forEmail(
                EmailAddress::fromString('seed-' . $email),
            ),
        );
    }

    private function users(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserProvisioningRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);

        return $users;
    }

    private function bindAppleFakes(App $app, VerifiedAppleIdToken $verified): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue(method_exists($container, 'set'));
        /** @var \DI\Container $container */
        $container->set(AppleAuthorizationCodeExchanger::class, new FakeAppleAuthorizationCodeExchangerForHttpTest('aaa.bbb.ccc'));
        $container->set(AppleIdTokenValidator::class, new FakeAppleIdTokenValidatorForHttpTest($verified));
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

final class FakeAppleAuthorizationCodeExchangerForHttpTest implements AppleAuthorizationCodeExchanger
{
    public function __construct(
        private readonly string $idToken,
    ) {
    }

    public function exchange(string $authorizationCode): string
    {
        unset($authorizationCode);

        return $this->idToken;
    }
}

final class FakeAppleIdTokenValidatorForHttpTest implements AppleIdTokenValidator
{
    public function __construct(
        private readonly VerifiedAppleIdToken $verified,
    ) {
    }

    public function validate(string $idToken, string $expectedNonce): VerifiedAppleIdToken
    {
        unset($idToken, $expectedNonce);

        return $this->verified;
    }
}
