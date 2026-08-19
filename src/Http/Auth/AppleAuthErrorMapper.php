<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\AppleAuthorizationChallengeInvalid;
use TriCab\Core\Identity\Application\Exception\AppleIdTokenInvalid;
use TriCab\Core\Identity\Application\Exception\AppleTokenExchangeFailed;
use TriCab\Core\Identity\Application\Exception\AppleUserNotRegistered;
use TriCab\Core\Identity\Application\Exception\GuestSessionIssuanceFailed;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * Maps Apple Sign-In failures to safe HTTP responses.
 * Never logs emails, codes, tokens, or secrets.
 */
final class AppleAuthErrorMapper
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
                'auth_provider' => 'apple',
                'reason' => $exception->errorCode,
            ]);

            return $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        if ($exception instanceof AppleUserNotRegistered || $exception instanceof UserNotRegistered) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
                'reason' => 'USER_NOT_REGISTERED',
            ]);

            return $this->responder->error(
                'USER_NOT_REGISTERED',
                'This Apple account is not linked to a registered TriCab user.',
                403,
            );
        }

        if ($exception instanceof IdentityAmbiguous) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
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
                'auth_provider' => 'apple',
                'reason' => 'IDENTITY_CONFLICT',
            ]);

            return $this->responder->error(
                'IDENTITY_CONFLICT',
                'This Apple sign-in could not be linked safely. Contact IT for help.',
                403,
            );
        }

        if ($exception instanceof AuthenticationNotAllowed) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
                'reason' => 'ACCOUNT_DISABLED',
            ]);

            return $this->responder->error(
                'ACCOUNT_DISABLED',
                'Your TriCab account is disabled. Contact IT for help.',
                403,
            );
        }

        if ($exception instanceof AppleAuthorizationChallengeInvalid) {
            $code = match ($exception->reason) {
                AppleAuthorizationChallengeInvalid::REASON_EXPIRED => 'AUTH_APPLE_STATE_EXPIRED',
                AppleAuthorizationChallengeInvalid::REASON_NONCE_MISMATCH => 'AUTH_APPLE_NONCE_MISMATCH',
                default => 'AUTH_APPLE_STATE_INVALID',
            };

            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
                'reason' => $code,
            ]);

            return $this->responder->error(
                $code,
                'Apple Sign-In challenge could not be verified. Please try again.',
                401,
            );
        }

        if ($exception instanceof AppleIdTokenInvalid) {
            $code = match ($exception->reason) {
                AppleIdTokenInvalid::REASON_NOT_CONFIGURED => 'APPLE_NOT_CONFIGURED',
                default => 'AUTH_APPLE_TOKEN_INVALID',
            };
            $status = $code === 'APPLE_NOT_CONFIGURED' ? 501 : 401;
            $message = $code === 'APPLE_NOT_CONFIGURED'
                ? 'Apple Sign-In is not fully configured on the server yet.'
                : 'Apple Sign-In could not be verified. Please try again.';

            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
                'reason' => $code,
            ]);

            return $this->responder->error($code, $message, $status);
        }

        if ($exception instanceof AppleTokenExchangeFailed) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
                'reason' => 'AUTH_APPLE_TOKEN_EXCHANGE_FAILED',
            ]);

            return $this->responder->error(
                'AUTH_APPLE_TOKEN_EXCHANGE_FAILED',
                'Apple Sign-In could not be completed. Please try again.',
                401,
            );
        }

        if (
            $exception instanceof InvalidIdentityValue
            && str_contains(strtolower($exception->getMessage()), 'email')
        ) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
                'reason' => 'AUTH_APPLE_TOKEN_INVALID',
            ]);

            return $this->responder->error(
                'AUTH_APPLE_TOKEN_INVALID',
                'Apple Sign-In could not be verified. Please try again.',
                401,
            );
        }

        if ($exception instanceof GuestSessionIssuanceFailed) {
            $this->logger->log($event, [
                'route' => $route,
                'auth_provider' => 'apple',
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
            'auth_provider' => 'apple',
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
