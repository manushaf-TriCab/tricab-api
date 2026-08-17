<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GoogleAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Request\AuthenticateGoogleRequest;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\AuthenticateWithGoogleService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;

final class AuthenticateGoogleAction
{
    private const ROUTE = '/api/v1/auth/google';

    public function __construct(
        private readonly AuthenticateWithGoogleService $authenticateWithGoogle,
        private readonly IssueGuestSessionService $issueGuestSession,
        private readonly JsonResponder $responder,
        private readonly GoogleAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = AuthenticateGoogleRequest::fromArray($body);
            $clientIp = GuestAuthRequestMetadata::clientIp($request);
            $userAgent = GuestAuthRequestMetadata::userAgent($request);

            $user = $this->authenticateWithGoogle->execute($input->idToken);

            $issued = $this->issueGuestSession->execute(
                user: $user,
                createdIpAddress: $clientIp,
                createdUserAgent: $userAgent,
            );

            $this->logger->log('google_login_success', [
                'route' => self::ROUTE,
                'auth_provider' => 'google',
                'user_public_id' => $user->publicId()->toString(),
                'email' => $user->emailAddress()->toString(),
                'client_ip' => $clientIp,
            ]);
            $this->logger->log('guest_session_issued', [
                'route' => self::ROUTE,
                'auth_provider' => 'google',
                'user_public_id' => $user->publicId()->toString(),
            ]);

            /** @var array<string, mixed> $userPayload */
            $userPayload = $issued->user;
            unset($userPayload['userId']);

            return $this->responder->success([
                'accessToken' => $issued->accessToken,
                'tokenType' => $issued->tokenType,
                'expiresIn' => $issued->expiresIn,
                'expiresAtUtc' => $issued->expiresAtUtc,
                'user' => $userPayload,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'google_login_failed');
        }
    }
}
