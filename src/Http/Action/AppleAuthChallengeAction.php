<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AppleAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AppleAuthorizationChallengeService;

final class AppleAuthChallengeAction
{
    private const ROUTE = '/api/v1/auth/apple/challenge';

    public function __construct(
        private readonly AppleAuthorizationChallengeService $challenges,
        private readonly JsonResponder $responder,
        private readonly AppleAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        try {
            $challenge = $this->challenges->issue();
            $this->logger->log('apple_challenge_issued', [
                'route' => self::ROUTE,
                'auth_provider' => 'apple',
            ]);

            return $this->responder->success($challenge);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'apple_challenge_failed');
        }
    }
}
