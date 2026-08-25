<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\PasskeyAuthErrorMapper;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Request\RequestGuestOtpRequest;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\RequestGuestPasskeySetupLinkService;

final class RequestPasskeySetupLinkAction
{
    private const ROUTE = '/api/v1/auth/guest/passkey/setup-link';

    public function __construct(
        private readonly RequestGuestPasskeySetupLinkService $service,
        private readonly JsonResponder $responder,
        private readonly PasskeyAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $input = RequestGuestOtpRequest::fromArray(JsonRequestBody::requireObject($request));
            $this->service->execute($input->email, GuestAuthRequestMetadata::clientIp($request));
            $this->logger->log('PASSKEY_SETUP_LINK_REQUESTED', ['route' => self::ROUTE]);

            return $this->responder->success(['status' => 'accepted'], 202);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'PASSKEY_SETUP_LINK_REQUESTED');
        }
    }
}
