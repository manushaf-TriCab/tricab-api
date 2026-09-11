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
use TriCab\Core\Identity\Application\Service\ExchangeTrustedDeviceSessionService;

final class ExchangeTrustedDeviceSessionAction
{
    private const ROUTE = '/api/v1/auth/devices/session';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ExchangeTrustedDeviceSessionService $exchangeTrustedDeviceSession,
        private readonly JsonResponder $responder,
        private readonly TrustedDeviceAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $publicId = $body['publicId'] ?? null;
            $credential = $body['credential'] ?? null;
            $installationId = $body['installationId'] ?? null;

            if (!is_string($publicId) || preg_match(self::UUID_PATTERN, strtolower(trim($publicId))) !== 1) {
                throw HttpRequestValidationFailed::unprocessable(
                    'AUTH_DEVICE_ID_INVALID',
                    'Enter a valid device identifier.',
                );
            }
            if (!is_string($credential) || trim($credential) === '') {
                throw HttpRequestValidationFailed::unprocessable(
                    'AUTH_DEVICE_CREDENTIAL_INVALID',
                    'Enter a valid device credential.',
                );
            }
            if (!is_string($installationId) || preg_match(self::UUID_PATTERN, strtolower(trim($installationId))) !== 1) {
                throw HttpRequestValidationFailed::unprocessable(
                    'AUTH_INSTALLATION_ID_INVALID',
                    'Enter a valid installation identifier.',
                );
            }

            $exchanged = $this->exchangeTrustedDeviceSession->execute(
                publicId: strtolower(trim($publicId)),
                rawCredential: trim($credential),
                installationId: strtolower(trim($installationId)),
                clientIp: GuestAuthRequestMetadata::clientIp($request),
                userAgent: GuestAuthRequestMetadata::userAgent($request),
            );

            $this->logger->log('trusted_device_session_issued', [
                'route' => self::ROUTE,
                'device_public_id' => $exchanged->deviceCredential->publicId,
            ]);

            /** @var array<string, mixed> $userPayload */
            $userPayload = $exchanged->session->user;
            unset($userPayload['userId']);

            return $this->responder->success([
                'accessToken' => $exchanged->session->accessToken,
                'tokenType' => $exchanged->session->tokenType,
                'expiresIn' => $exchanged->session->expiresIn,
                'expiresAtUtc' => $exchanged->session->expiresAtUtc,
                'user' => $userPayload,
                'deviceCredential' => $exchanged->deviceCredential->toArray(),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'trusted_device_session_failed');
        }
    }
}
