<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\ActiveOtpChallengeNotFound;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Application\Exception\GuestSessionIssuanceFailed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Exception\OtpAttemptsExceeded;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeConsumed;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeExpired;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeInvalidated;
use TriCab\Core\Identity\Domain\Exception\OtpInvalid;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Maps guest-auth domain/application failures to safe HTTP responses.
 */
final class GuestAuthErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function map(Throwable $exception, string $route, string $event): ResponseInterface
    {
        if ($exception instanceof GuestAuthRateLimited) {
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
            $exception instanceof InvalidIdentityValue
            && str_contains(strtolower($exception->getMessage()), 'email')
        ) {
            $this->logger->log($event, [
                'route' => $route,
                'reason' => 'AUTH_EMAIL_INVALID',
            ]);

            return $this->responder->error(
                'AUTH_EMAIL_INVALID',
                'Enter a valid email address.',
                422,
            );
        }

        if ($exception instanceof GuestLoginNotEligible) {
            // Business-approved enumeration response: one public message for all rejection reasons.
            // Caller (RequestGuestOtpAction) logs guest_otp_eligibility_rejected with email fingerprint.
            return $this->responder->error(
                'AUTH_CUSTOMER_LOGIN_NOT_REGISTERED',
                'This email is not registered for TriCab access. Please contact your nearest TriCab branch to request an account.',
                403,
            );
        }

        if ($exception instanceof GuestOtpEmailDeliveryFailed) {
            $previous = $exception->getPrevious();
            $this->logger->log('guest_otp_request_failed', [
                'route' => $route,
                'reason' => 'AUTH_OTP_DELIVERY_FAILED',
                // Safe diagnostics only — never OTP, tokens, or provider secrets.
                'underlying_exception' => $previous instanceof Throwable ? $previous::class : null,
                'underlying_message' => $previous instanceof Throwable
                    ? substr($previous->getMessage(), 0, 240)
                    : null,
            ]);

            return $this->responder->error(
                'AUTH_OTP_DELIVERY_FAILED',
                'We could not send the login code. Please try again.',
                503,
            );
        }

        if ($exception instanceof GuestSessionIssuanceFailed) {
            $previous = $exception->getPrevious();
            $pdo = null;
            $cursor = $previous;
            while ($cursor instanceof Throwable) {
                if ($cursor instanceof \PDOException) {
                    $pdo = $cursor;
                    break;
                }
                $cursor = $cursor->getPrevious();
            }

            $sqlState = null;
            $driverCode = null;
            $driverMessage = null;
            if ($pdo instanceof \PDOException) {
                $rawState = $pdo->errorInfo[0] ?? $pdo->getCode();
                if (is_string($rawState) || is_int($rawState)) {
                    $sqlState = (string) $rawState;
                }

                $rawDriverCode = $pdo->errorInfo[1] ?? null;
                if (is_int($rawDriverCode) || (is_string($rawDriverCode) && is_numeric($rawDriverCode))) {
                    $driverCode = (int) $rawDriverCode;
                }

                $rawDriverMessage = $pdo->errorInfo[2] ?? null;
                if (is_string($rawDriverMessage)) {
                    $driverMessage = substr($rawDriverMessage, 0, 240);
                }
            }

            $this->logger->log('guest_otp_verification_failed', [
                'route' => $route,
                'reason' => 'AUTH_SESSION_CREATION_FAILED',
                'internal_reason' => $exception::class,
                'underlying_exception' => $previous instanceof Throwable ? $previous::class : null,
                'underlying_message' => $previous instanceof Throwable
                    ? substr($previous->getMessage(), 0, 240)
                    : null,
                'sql_state' => $sqlState,
                'driver_code' => $driverCode,
                'driver_message' => $driverMessage,
            ]);

            return $this->responder->error(
                'AUTH_SESSION_CREATION_FAILED',
                'We could not create your session. Please try again.',
                503,
            );
        }

        if ($this->isSafeOtpFailure($exception)) {
            $this->logger->log($event, [
                'route' => $route,
                'reason' => 'AUTH_OTP_INVALID',
                'internal_reason' => $exception::class,
            ]);

            return $this->responder->error(
                'AUTH_OTP_INVALID',
                'The verification code is invalid or has expired.',
                401,
            );
        }

        $this->logger->log($event, [
            'route' => $route,
            'reason' => 'INTERNAL_ERROR',
            'internal_reason' => $exception::class,
        ]);

        return $this->responder->error(
            'INTERNAL_ERROR',
            'An unexpected error occurred.',
            500,
        );
    }

    private function isSafeOtpFailure(Throwable $exception): bool
    {
        return $exception instanceof OtpInvalid
            || $exception instanceof OtpChallengeExpired
            || $exception instanceof OtpChallengeConsumed
            || $exception instanceof OtpChallengeInvalidated
            || $exception instanceof OtpAttemptsExceeded
            || $exception instanceof ActiveOtpChallengeNotFound
            || (
                $exception instanceof InvalidIdentityValue
                && (
                    str_contains(strtolower($exception->getMessage()), 'otp')
                    || str_contains(strtolower($exception->getMessage()), 'uuid')
                )
            );
    }
}
