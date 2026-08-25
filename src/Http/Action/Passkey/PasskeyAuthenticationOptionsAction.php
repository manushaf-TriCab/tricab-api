<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\PasskeyAuthErrorMapper;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\StartPasskeyAuthenticationService;

final class PasskeyAuthenticationOptionsAction
{
    private const ROUTE = '/api/v1/auth/passkeys/authentication/options';

    public function __construct(
        private readonly StartPasskeyAuthenticationService $service,
        private readonly JsonResponder $responder,
        private readonly PasskeyAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $result = $this->service->execute(GuestAuthRequestMetadata::clientIp($request));
            $this->logger->log('PASSKEY_AUTH_STARTED', ['route' => self::ROUTE]);

            return $this->responder->success($result);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'PASSKEY_AUTH_FAILED');
        }
    }
}
