<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\PasskeyAuthFailed;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class PasskeyAuthErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function map(Throwable $exception, string $route, string $event): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            $this->logger->log($event, ['route' => $route, 'reason' => $exception->errorCode]);

            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof InvalidIdentityValue && str_contains(strtolower($exception->getMessage()), 'email')) {
            $this->logger->log($event, ['route' => $route, 'reason' => 'AUTH_EMAIL_INVALID']);

            return $this->responder->error('AUTH_EMAIL_INVALID', 'Enter a valid email address.', 422);
        }

        if ($exception instanceof PasskeyAuthFailed) {
            $this->logger->log($event, ['route' => $route, 'reason' => $exception->errorCode]);

            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof AuthenticationNotAllowed) {
            $this->logger->log($event, ['route' => $route, 'reason' => 'ACCOUNT_DISABLED']);

            return $this->responder->error('ACCOUNT_DISABLED', 'This TriCab account is not active.', 403);
        }

        $this->logger->log($event, ['route' => $route, 'reason' => $exception::class]);

        return $this->responder->error('AUTH_PASSKEY_FAILED', 'Passkey sign-in could not be completed.', 401);
    }
}
