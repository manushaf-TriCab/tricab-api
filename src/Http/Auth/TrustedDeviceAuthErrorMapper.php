<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceExpired;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceIneligible;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceInvalidCredential;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceNotFound;
use TriCab\Core\Identity\Domain\Exception\TrustedDeviceRevoked;

/**
 * Maps trusted-device failures to safe public responses. Never logs credentials/tokens.
 */
final class TrustedDeviceAuthErrorMapper
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
                'reason' => $exception->errorCode,
            ]);

            return $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        if (
            $exception instanceof TrustedDeviceNotFound
            || $exception instanceof TrustedDeviceInvalidCredential
            || $exception instanceof TrustedDeviceExpired
            || $exception instanceof TrustedDeviceRevoked
            || $exception instanceof InvalidIdentityValue
        ) {
            $this->logger->log($event, [
                'route' => $route,
                'reason' => 'AUTH_TRUSTED_DEVICE_INVALID',
                'exception' => $exception::class,
            ]);

            return $this->responder->error(
                'AUTH_TRUSTED_DEVICE_INVALID',
                'Trusted device sign-in is no longer valid. Please sign in again.',
                401,
            );
        }

        if (
            $exception instanceof GuestLoginNotEligible
            || $exception instanceof TrustedDeviceIneligible
            || $exception instanceof AuthenticationNotAllowed
        ) {
            $this->logger->log($event, [
                'route' => $route,
                'reason' => 'AUTH_CUSTOMER_LOGIN_NOT_REGISTERED',
                'exception' => $exception::class,
            ]);

            return $this->responder->error(
                'AUTH_CUSTOMER_LOGIN_NOT_REGISTERED',
                'This email is not registered for TriCab access. Please contact your nearest TriCab branch to request an account.',
                403,
            );
        }

        $this->logger->log($event, [
            'route' => $route,
            'reason' => 'AUTH_TRUSTED_DEVICE_FAILED',
            'exception' => $exception::class,
        ]);

        return $this->responder->error(
            'AUTH_TRUSTED_DEVICE_FAILED',
            'Trusted device sign-in failed. Please try again.',
            500,
        );
    }
}
