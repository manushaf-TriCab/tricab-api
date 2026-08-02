<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Http\Auth\GuestAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Application\Exception\GuestSessionIssuanceFailed;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use RuntimeException;

final class GuestAuthEndpointsTest extends TestCase
{
    public function test_request_otp_returns_202_with_masked_email_and_no_otp(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/request-otp', [
            'email' => 'guest@example.com',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertArrayHasKey('challengeId', $data);
        self::assertSame('g***t@example.com', $data['email']);
        self::assertArrayHasKey('expiresAtUtc', $data);
        self::assertArrayHasKey('expiresInSeconds', $data);
        self::assertIsInt($data['expiresInSeconds']);
        self::assertGreaterThan(0, $data['expiresInSeconds']);
        self::assertLessThanOrEqual(300, $data['expiresInSeconds']);
        $encoded = (string) $response->getBody();
        self::assertStringNotContainsString('"otp"', $encoded);
        self::assertDoesNotMatchRegularExpression('/"otp"\s*:\s*"\d{6}"/', $encoded);
    }

    public function test_request_otp_rejects_invalid_and_missing_email(): void
    {
        $app = TestAppFactory::create();

        $invalid = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/request-otp', [
            'email' => 'not-an-email',
        ]));
        $invalidPayload = TestAppFactory::json($invalid);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertIsArray($invalidPayload['error']);
        /** @var array<string, mixed> $invalidError */
        $invalidError = $invalidPayload['error'];
        self::assertSame('AUTH_EMAIL_INVALID', $invalidError['code']);

        $missing = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/request-otp', []));
        $missingPayload = TestAppFactory::json($missing);
        self::assertSame(422, $missing->getStatusCode());
        self::assertIsArray($missingPayload['error']);
        /** @var array<string, mixed> $missingError */
        $missingError = $missingPayload['error'];
        self::assertSame('AUTH_EMAIL_INVALID', $missingError['code']);
    }

    public function test_request_otp_rejects_malformed_json(): void
    {
        $app = TestAppFactory::create();
        $request = TestAppFactory::request('POST', '/api/v1/auth/guest/request-otp')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{not-json');

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('REQUEST_INVALID', $error['code']);
    }

    public function test_request_otp_email_failure_returns_safe_503(): void
    {
        $mapper = new GuestAuthErrorMapper(new JsonResponder(), new GuestAuthEventLogger());
        $response = $mapper->map(
            GuestOtpEmailDeliveryFailed::fromThrowable(
                new RuntimeException('Postmark token xyz-secret-123 failed'),
            ),
            '/api/v1/auth/guest/request-otp',
            'guest_otp_request_failed',
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('AUTH_OTP_DELIVERY_FAILED', $error['code']);
        $raw = (string) $response->getBody();
        self::assertStringNotContainsString('Postmark', $raw);
        self::assertStringNotContainsString('xyz-secret', $raw);
    }

    public function test_verify_otp_happy_path_and_me_compatibility(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        [$challengeId, $otp] = $this->requestOtp($app, 'guest@example.com');

        $verify = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => 'guest@example.com',
            'otp' => $otp,
        ]));
        $payload = TestAppFactory::json($verify);

        self::assertSame(200, $verify->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['accessToken']);
        self::assertSame('Bearer', $data['tokenType']);
        self::assertSame(86400, $data['expiresIn']);
        self::assertIsArray($data['user']);
        /** @var array<string, mixed> $user */
        $user = $data['user'];
        self::assertArrayNotHasKey('userId', $user);
        self::assertArrayHasKey('publicId', $user);
        self::assertSame('guest@example.com', $user['emailAddress']);
        self::assertIsArray($user['roles']);
        self::assertIsArray($user['permissions']);
        self::assertContains('GUEST', $user['roles']);
        self::assertContains('ACCOUNT.LOGIN', $user['permissions']);
        self::assertArrayNotHasKey('tokenHash', $data);
        self::assertStringNotContainsString($otp, (string) $verify->getBody());

        $me = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')
                ->withHeader('Authorization', 'Bearer ' . $data['accessToken']),
        );
        $mePayload = TestAppFactory::json($me);
        self::assertSame(200, $me->getStatusCode());
        self::assertIsArray($mePayload['data']);
        /** @var array<string, mixed> $meData */
        $meData = $mePayload['data'];
        self::assertSame('guest@example.com', $meData['emailAddress']);
        self::assertIsArray($meData['roles']);
        self::assertContains('GUEST', $meData['roles']);
        self::assertArrayHasKey('userId', $meData);
    }

    public function test_verify_otp_must_be_string_and_uuid_valid(): void
    {
        $app = TestAppFactory::create();

        $numericOtp = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => '550e8400-e29b-41d4-a716-446655440010',
            'email' => 'guest@example.com',
            'otp' => 123456,
        ]));
        $numericPayload = TestAppFactory::json($numericOtp);
        self::assertSame(422, $numericOtp->getStatusCode());
        self::assertIsArray($numericPayload['error']);
        /** @var array<string, mixed> $numericError */
        $numericError = $numericPayload['error'];
        self::assertSame('AUTH_OTP_INVALID', $numericError['code']);

        $badUuid = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => 'not-a-uuid',
            'email' => 'guest@example.com',
            'otp' => '123456',
        ]));
        $badUuidPayload = TestAppFactory::json($badUuid);
        self::assertSame(422, $badUuid->getStatusCode());
        self::assertIsArray($badUuidPayload['error']);
        /** @var array<string, mixed> $badUuidError */
        $badUuidError = $badUuidPayload['error'];
        self::assertSame('AUTH_CHALLENGE_INVALID', $badUuidError['code']);
    }

    public function test_verify_otp_invalid_consumed_and_unknown_return_safe_401(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        [$challengeId] = $this->requestOtp($app, 'guest@example.com');
        $invalid = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => 'guest@example.com',
            'otp' => '000000',
        ]));
        $invalidPayload = TestAppFactory::json($invalid);
        self::assertSame(401, $invalid->getStatusCode());
        self::assertIsArray($invalidPayload['error']);
        /** @var array<string, mixed> $invalidError */
        $invalidError = $invalidPayload['error'];
        self::assertSame('AUTH_OTP_INVALID', $invalidError['code']);

        [$challengeId2] = $this->requestOtp($app, 'attempts@example.com');
        for ($i = 0; $i < 5; ++$i) {
            $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
                'challengeId' => $challengeId2,
                'email' => 'attempts@example.com',
                'otp' => '000000',
            ]));
            $payload = TestAppFactory::json($response);
            self::assertSame(401, $response->getStatusCode());
            self::assertIsArray($payload['error']);
            /** @var array<string, mixed> $error */
            $error = $payload['error'];
            self::assertSame('AUTH_OTP_INVALID', $error['code']);
        }

        [$challengeId3, $otp3] = $this->requestOtp($app, 'consume@example.com');
        $first = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId3,
            'email' => 'consume@example.com',
            'otp' => $otp3,
        ]));
        self::assertSame(200, $first->getStatusCode());
        $second = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId3,
            'email' => 'consume@example.com',
            'otp' => $otp3,
        ]));
        $secondPayload = TestAppFactory::json($second);
        self::assertSame(401, $second->getStatusCode());
        self::assertIsArray($secondPayload['error']);
        /** @var array<string, mixed> $secondError */
        $secondError = $secondPayload['error'];
        self::assertSame('AUTH_OTP_INVALID', $secondError['code']);

        $unknown = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => '550e8400-e29b-41d4-a716-446655440099',
            'email' => 'expire@example.com',
            'otp' => '123456',
        ]));
        $unknownPayload = TestAppFactory::json($unknown);
        self::assertSame(401, $unknown->getStatusCode());
        self::assertIsArray($unknownPayload['error']);
        /** @var array<string, mixed> $unknownError */
        $unknownError = $unknownPayload['error'];
        self::assertSame('AUTH_OTP_INVALID', $unknownError['code']);
    }

    public function test_logout_guest_is_idempotent_and_blocks_me(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        [$challengeId, $otp] = $this->requestOtp($app, 'logout@example.com');
        $verify = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => 'logout@example.com',
            'otp' => $otp,
        ]));
        $verifyPayload = TestAppFactory::json($verify);
        self::assertIsArray($verifyPayload['data']);
        /** @var array<string, mixed> $verifyData */
        $verifyData = $verifyPayload['data'];
        self::assertIsString($verifyData['accessToken']);
        $token = $verifyData['accessToken'];

        $logout = $app->handle(
            TestAppFactory::request('POST', '/api/v1/auth/guest/logout')
                ->withHeader('Authorization', 'Bearer ' . $token),
        );
        self::assertSame(200, $logout->getStatusCode());
        self::assertTrue(TestAppFactory::json($logout)['success']);

        $logoutAgain = $app->handle(
            TestAppFactory::request('POST', '/api/v1/auth/guest/logout')
                ->withHeader('Authorization', 'Bearer ' . $token),
        );
        self::assertSame(200, $logoutAgain->getStatusCode());
        self::assertTrue(TestAppFactory::json($logoutAgain)['success']);

        $me = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')
                ->withHeader('Authorization', 'Bearer ' . $token),
        );
        self::assertSame(401, $me->getStatusCode());
    }

    public function test_logout_rejects_entra_and_missing_token(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'true']);

        $entraLogout = $app->handle(
            TestAppFactory::request('POST', '/api/v1/auth/guest/logout')
                ->withHeader('X-Dev-Entra-Tenant', 'development-tenant')
                ->withHeader('X-Dev-Entra-Subject', 'development-thilan'),
        );
        $entraPayload = TestAppFactory::json($entraLogout);
        self::assertSame(403, $entraLogout->getStatusCode());
        self::assertIsArray($entraPayload['error']);
        /** @var array<string, mixed> $entraError */
        $entraError = $entraPayload['error'];
        self::assertSame('AUTH_GUEST_SESSION_REQUIRED', $entraError['code']);

        $missing = $app->handle(TestAppFactory::request('POST', '/api/v1/auth/guest/logout'));
        self::assertSame(403, $missing->getStatusCode());

        $appNoDev = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $unauthenticated = $appNoDev->handle(TestAppFactory::request('POST', '/api/v1/auth/guest/logout'));
        self::assertSame(401, $unauthenticated->getStatusCode());
    }

    public function test_me_still_works_with_dev_entra_identity(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'true']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/me'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('thilanh@tricab.com', $data['emailAddress']);
    }

    public function test_session_persistence_failure_does_not_return_token(): void
    {
        $mapper = new GuestAuthErrorMapper(new JsonResponder(), new GuestAuthEventLogger());
        $response = $mapper->map(
            GuestSessionIssuanceFailed::persistenceFailed(new RuntimeException('db down')),
            '/api/v1/auth/guest/verify-otp',
            'guest_otp_verification_failed',
        );
        $payload = TestAppFactory::json($response);
        $raw = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode());
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('AUTH_SESSION_CREATION_FAILED', $error['code']);
        self::assertStringNotContainsString('accessToken', $raw);
        self::assertStringNotContainsString('eyJ', $raw);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{0: string, 1: string}
     */
    private function requestOtp(App $app, string $email): array
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/request-otp', [
            'email' => $email,
        ]));
        self::assertSame(202, $response->getStatusCode());
        $payload = TestAppFactory::json($response);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['challengeId']);
        $challengeId = $data['challengeId'];

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $sender = $container->get(GuestOtpEmailSender::class);
        self::assertInstanceOf(DevelopmentGuestOtpEmailSender::class, $sender);
        $last = $sender->lastMessage();
        self::assertNotNull($last);
        self::assertSame(strtolower($email), $last['to']);

        return [$challengeId, $last['otpCode']];
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
