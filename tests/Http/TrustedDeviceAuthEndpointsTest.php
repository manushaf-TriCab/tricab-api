<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Service\GoogleExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class TrustedDeviceAuthEndpointsTest extends TestCase
{
    private const INSTALLATION = '11111111-1111-4111-8111-111111111111';

    public function test_register_requires_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/devices/register', [
            'installationId' => self::INSTALLATION,
            'platform' => 'ios',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertIsArray($payload['error']);
    }

    public function test_register_session_revoke_round_trip(): void
    {
        $app = TestAppFactory::create();
        $token = $this->signInWithOtp($app, 'device-user@example.com');

        $register = $app->handle(
            $this->jsonRequest('POST', '/api/v1/auth/devices/register', [
                'installationId' => self::INSTALLATION,
                'platform' => 'ios',
                'deviceName' => 'iPhone',
            ])->withHeader('Authorization', 'Bearer ' . $token),
        );
        $registerPayload = TestAppFactory::json($register);
        self::assertSame(200, $register->getStatusCode(), (string) $register->getBody());
        self::assertIsArray($registerPayload['data']);
        /** @var array<string, mixed> $device */
        $device = $registerPayload['data'];
        self::assertIsString($device['publicId'] ?? null);
        self::assertIsString($device['credential'] ?? null);
        self::assertIsString($device['expiresAtUtc'] ?? null);

        $session = $app->handle($this->jsonRequest('POST', '/api/v1/auth/devices/session', [
            'publicId' => $device['publicId'],
            'credential' => $device['credential'],
            'installationId' => self::INSTALLATION,
        ]));
        $sessionPayload = TestAppFactory::json($session);
        self::assertSame(200, $session->getStatusCode(), (string) $session->getBody());
        self::assertIsArray($sessionPayload['data']);
        /** @var array<string, mixed> $sessionData */
        $sessionData = $sessionPayload['data'];
        self::assertArrayHasKey('accessToken', $sessionData);
        self::assertIsArray($sessionData['deviceCredential'] ?? null);
        self::assertNotSame($device['credential'], $sessionData['deviceCredential']['credential']);

        $me = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')
                ->withHeader('Authorization', 'Bearer ' . Payload::str($sessionData['accessToken'])),
        );
        self::assertSame(200, $me->getStatusCode());

        $wrong = $app->handle($this->jsonRequest('POST', '/api/v1/auth/devices/session', [
            'publicId' => $device['publicId'],
            'credential' => 'not-the-credential',
            'installationId' => self::INSTALLATION,
        ]));
        $wrongPayload = TestAppFactory::json($wrong);
        self::assertSame(401, $wrong->getStatusCode());
        self::assertIsArray($wrongPayload['error']);
        self::assertSame('AUTH_TRUSTED_DEVICE_INVALID', $wrongPayload['error']['code']);

        $revoke = $app->handle(
            $this->jsonRequest('DELETE', '/api/v1/auth/devices/current', [
                'publicId' => $device['publicId'],
            ])->withHeader('Authorization', 'Bearer ' . Payload::str($sessionData['accessToken'])),
        );
        self::assertSame(200, $revoke->getStatusCode(), (string) $revoke->getBody());

        $afterRevoke = $app->handle($this->jsonRequest('POST', '/api/v1/auth/devices/session', [
            'publicId' => $device['publicId'],
            'credential' => $sessionData['deviceCredential']['credential'],
            'installationId' => self::INSTALLATION,
        ]));
        self::assertSame(401, $afterRevoke->getStatusCode());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function signInWithOtp(App $app, string $email): string
    {
        [$challengeId, $otp] = $this->requestOtp($app, $email);
        $verify = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/verify-otp', [
            'challengeId' => $challengeId,
            'email' => $email,
            'otp' => $otp,
        ]));
        $payload = TestAppFactory::json($verify);
        self::assertSame(200, $verify->getStatusCode());
        self::assertIsArray($payload['data']);

        return Payload::str(Payload::obj($payload['data'])['accessToken']);
    }

    /**
     * @return array{0: string, 1: string}
     * @param App<ContainerInterface> $app
     */
    private function requestOtp(App $app, string $email): array
    {
        $this->seedEligiblePortalUser($app, $email);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/auth/guest/request-otp', [
            'email' => $email,
        ]));
        self::assertSame(202, $response->getStatusCode());
        $payload = TestAppFactory::json($response);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['challengeId']);

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $sender = $container->get(GuestOtpEmailSender::class);
        self::assertInstanceOf(DevelopmentGuestOtpEmailSender::class, $sender);
        $last = $sender->lastMessage();
        self::assertNotNull($last);

        return [$data['challengeId'], $last['otpCode']];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedEligiblePortalUser(App $app, string $email): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        $users = $container->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);

        $emailAddress = EmailAddress::fromString($email);
        if ($users->findByEmail($emailAddress) !== null) {
            return;
        }

        $users->register(
            emailAddress: $emailAddress,
            displayName: 'Portal ' . $emailAddress->toString(),
            status: UserStatus::active(),
            roles: [RoleCode::fromString('GUEST')],
            permissions: [PermissionCode::fromString('ACCOUNT.LOGIN')],
            identity: GoogleExternalIdentityFactory::forSubject('seed-' . $emailAddress->toString()),
        );
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
