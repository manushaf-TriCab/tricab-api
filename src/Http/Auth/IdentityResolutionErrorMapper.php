<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ResponseInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/** Maps identity resolution failures to safe HTTP responses. */
final class IdentityResolutionErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(UserNotRegistered|IdentityConflict|IdentityAmbiguous|AuthenticationNotAllowed $exception): ResponseInterface
    {
        if ($exception instanceof UserNotRegistered) {
            return $this->responder->error(
                'USER_NOT_REGISTERED',
                'No registered TriCab user matches this verified identity.',
                403,
            );
        }

        if ($exception instanceof IdentityAmbiguous) {
            return $this->responder->error(
                'IDENTITY_AMBIGUOUS',
                'Multiple TriCab accounts match this verified email. Contact IT for help.',
                403,
            );
        }

        if ($exception instanceof IdentityConflict) {
            return $this->responder->error(
                'IDENTITY_CONFLICT',
                'This sign-in could not be linked safely. Contact IT for help.',
                403,
            );
        }

        return $this->mapAuthenticationNotAllowed($exception);
    }

    private function mapAuthenticationNotAllowed(AuthenticationNotAllowed $exception): ResponseInterface
    {
        $message = $exception->getMessage();

        if (str_contains($message, '"' . UserStatus::PENDING . '"')) {
            return $this->responder->error(
                'ACCOUNT_PENDING',
                'This TriCab account is pending activation.',
                403,
            );
        }

        return $this->responder->error(
            'ACCOUNT_DISABLED',
            'This TriCab account is disabled.',
            403,
        );
    }
}
