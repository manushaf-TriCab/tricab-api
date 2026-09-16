<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TrustedDevice;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\TrustedDeviceAuthErrorMapper;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Service\RegisterTrustedDeviceService;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class RegisterTrustedDeviceAction
{
    private const ROUTE = '/api/v1/auth/devices/register';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly RegisterTrustedDeviceService $registerTrustedDevice,
        private readonly UserRepository $users,
        private readonly JsonResponder $responder,
        private readonly TrustedDeviceAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $authenticated = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if (!$authenticated instanceof AuthenticatedUser) {
                return $this->responder->error('AUTHENTICATION_REQUIRED', 'Sign in to register this device.', 401);
            }

            $body = JsonRequestBody::requireObject($request);
            $installationId = $body['installationId'] ?? null;
            $platform = $body['platform'] ?? null;
            $deviceName = $body['deviceName'] ?? null;

            if (!is_string($installationId) || preg_match(self::UUID_PATTERN, strtolower(trim($installationId))) !== 1) {
                throw HttpRequestValidationFailed::unprocessable(
                    'AUTH_INSTALLATION_ID_INVALID',
                    'Enter a valid installation identifier.',
                );
            }
            if (!is_string($platform) || !in_array(strtolower(trim($platform)), ['ios', 'android'], true)) {
                throw HttpRequestValidationFailed::unprocessable(
                    'AUTH_PLATFORM_INVALID',
                    'Platform must be ios or android.',
                );
            }
            if ($deviceName !== null && !is_string($deviceName)) {
                throw HttpRequestValidationFailed::unprocessable(
                    'AUTH_DEVICE_NAME_INVALID',
                    'Device name must be a string.',
                );
            }

            $user = $this->users->findById(UserId::fromInt($authenticated->userId));
            if ($user === null) {
                return $this->responder->error('AUTHENTICATION_REQUIRED', 'Sign in to register this device.', 401);
            }

            $registered = $this->registerTrustedDevice->execute(
                user: $user,
                installationId: strtolower(trim($installationId)),
                platform: strtolower(trim($platform)),
                deviceName: is_string($deviceName) ? $deviceName : null,
                createdIp: GuestAuthRequestMetadata::clientIp($request),
            );

            $this->logger->log('trusted_device_registered', [
                'route' => self::ROUTE,
                'user_public_id' => $authenticated->publicId,
                'device_public_id' => $registered->publicId,
                'platform' => strtolower(trim($platform)),
            ]);

            return $this->responder->success($registered->toArray());
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'trusted_device_register_failed');
        }
    }
}
