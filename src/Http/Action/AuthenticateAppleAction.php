<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AppleAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Request\AuthenticateAppleRequest;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AppleAuthorizationChallengeService;
use TriCab\Core\Identity\Application\Service\AuthenticateWithAppleService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;

final class AuthenticateAppleAction
{
    private const ROUTE = '/api/v1/auth/apple';

    public function __construct(
        private readonly AppleAuthorizationChallengeService $challenges,
        private readonly AuthenticateWithAppleService $authenticateWithApple,
        private readonly IssueGuestSessionService $issueGuestSession,
        private readonly JsonResponder $responder,
        private readonly AppleAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = AuthenticateAppleRequest::fromArray($body);
            $clientIp = GuestAuthRequestMetadata::clientIp($request);
            $userAgent = GuestAuthRequestMetadata::userAgent($request);

            $this->challenges->validate($input->state, $input->nonce);

            $user = $this->authenticateWithApple->execute(
                $input->authorizationCode,
                $input->nonce,
            );

            $issued = $this->issueGuestSession->execute(
                user: $user,
                createdIpAddress: $clientIp,
                createdUserAgent: $userAgent,
            );

            // Do not log email — Apple may return a private relay address.
            $this->logger->log('apple_login_success', [
                'route' => self::ROUTE,
                'auth_provider' => 'apple',
                'user_public_id' => $user->publicId()->toString(),
                'client_ip' => $clientIp,
            ]);
            $this->logger->log('guest_session_issued', [
                'route' => self::ROUTE,
                'auth_provider' => 'apple',
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
            return $this->errors->map($exception, self::ROUTE, 'apple_login_failed');
        }
    }
}
