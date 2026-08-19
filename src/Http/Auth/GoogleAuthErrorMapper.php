<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\GoogleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\GoogleUserNotRegistered;
use TriCab\Core\Identity\Application\Exception\GuestSessionIssuanceFailed;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * Maps Google Sign-In failures to safe HTTP responses.
 */
final class GoogleAuthErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function map(Throwable $exception, string $route, string $event): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => $exception->errorCode,
            ]);

            return $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        if ($exception instanceof GoogleUserNotRegistered || $exception instanceof UserNotRegistered) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => 'USER_NOT_REGISTERED',
            ]);

            return $this->responder->error(
                'USER_NOT_REGISTERED',
                'Your Google account is not registered with TriCab.',
                403,
            );
        }

        if ($exception instanceof IdentityAmbiguous) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => 'IDENTITY_AMBIGUOUS',
            ]);

            return $this->responder->error(
                'IDENTITY_AMBIGUOUS',
                'Multiple TriCab accounts match this verified email. Contact IT for help.',
                403,
            );
        }

        if ($exception instanceof IdentityConflict) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => 'IDENTITY_CONFLICT',
            ]);

            return $this->responder->error(
                'IDENTITY_CONFLICT',
                'This Google sign-in could not be linked safely. Contact IT for help.',
                403,
            );
        }

        if ($exception instanceof AuthenticationNotAllowed) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => 'ACCOUNT_DISABLED',
            ]);

            return $this->responder->error(
                'ACCOUNT_DISABLED',
                'Your TriCab account is disabled. Contact IT for help.',
                403,
            );
        }

        if ($exception instanceof GoogleIdTokenInvalid) {
            $code = str_contains(strtolower($exception->getMessage()), 'not configured')
                ? 'GOOGLE_NOT_CONFIGURED'
                : 'AUTH_GOOGLE_TOKEN_INVALID';
            $status = $code === 'GOOGLE_NOT_CONFIGURED' ? 501 : 401;
            $message = $code === 'GOOGLE_NOT_CONFIGURED'
                ? 'Google Sign-In is not fully configured on the server yet.'
                : 'Google Sign-In could not be verified. Please try again.';

            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => $code,
            ]);

            return $this->responder->error($code, $message, $status);
        }

        if (
            $exception instanceof InvalidIdentityValue
            && str_contains(strtolower($exception->getMessage()), 'email')
        ) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => 'AUTH_GOOGLE_TOKEN_INVALID',
            ]);

            return $this->responder->error(
                'AUTH_GOOGLE_TOKEN_INVALID',
                'Google Sign-In could not be verified. Please try again.',
                401,
            );
        }

        if ($exception instanceof GuestSessionIssuanceFailed) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'google',
                'reason' => 'AUTH_SESSION_CREATION_FAILED',
            ]);

            return $this->responder->error(
                'AUTH_SESSION_CREATION_FAILED',
                'We could not create your session. Please try again.',
                503,
            );
        }

        $this->logger->log($event, [
            'route' => $route,
            'auth_provider' => 'google',
            'reason' => 'INTERNAL_ERROR',
            'internal_reason' => $exception::class,
        ]);

        return $this->responder->error(
            'INTERNAL_ERROR',
            'An unexpected error occurred.',
            500,
        );
    }
}
