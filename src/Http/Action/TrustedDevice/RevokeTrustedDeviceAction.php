<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\TrustedDevice;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\TrustedDeviceAuthErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Request\RevokeTrustedDeviceRequest;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Service\RevokeTrustedDeviceService;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class RevokeTrustedDeviceAction
{
    private const ROUTE = '/api/v1/auth/devices/current';

    public function __construct(
        private readonly RevokeTrustedDeviceService $revokeTrustedDevice,
        private readonly JsonResponder $responder,
        private readonly TrustedDeviceAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $authUser = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if (!$authUser instanceof AuthenticatedUser) {
                return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
            }

            $body = JsonRequestBody::requireObject($request);
            $input = RevokeTrustedDeviceRequest::fromArray($body);

            $this->revokeTrustedDevice->revokeCurrent(
                userId: UserId::fromInt($authUser->userId),
                publicId: $input->publicId,
                installationId: $input->installationId,
            );

            $this->logger->log('trusted_device_revoked', [
                'route' => self::ROUTE,
                'device_public_id' => $input->publicId,
                'user_public_id' => $authUser->publicId,
            ]);

            return $this->responder->success(['status' => 'revoked']);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'trusted_device_revoke_failed');
        }
    }
}
