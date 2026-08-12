<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Core\Customer\Application\Exception\CustomerCodeConflict;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerContactUserLinkNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Application\Exception\PortalUserProvisioningFailed;
use TriCab\Core\Customer\Domain\Exception\DuplicateContactUserLink;
use TriCab\Core\Customer\Domain\Exception\InactiveContactPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InactiveCustomerPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerRoleAssignment;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\Exception\InvalidPortalAccessTransition;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

/**
 * Maps Core Customer application/domain exceptions (plus a few Identity
 * exceptions surfaced while resolving portal-user links) to safe JSON errors
 * for the admin Customer/Contact/Portal endpoints.
 */
final class CustomerManagementErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof DatabaseTemporarilyUnavailable) {
            return $this->responder->error(
                'DATABASE_TEMPORARILY_UNAVAILABLE',
                'The database is temporarily unavailable.',
                503,
            );
        }

        if ($exception instanceof DatabaseUnavailable) {
            return $this->responder->error('DATABASE_UNAVAILABLE', 'The database is temporarily unavailable.', 503);
        }

        if ($exception instanceof CustomerNotFound) {
            return $this->responder->error('CUSTOMER_NOT_FOUND', 'The requested customer was not found.', 404);
        }

        if ($exception instanceof CustomerContactNotFound) {
            return $this->responder->error('CUSTOMER_CONTACT_NOT_FOUND', 'The requested customer contact was not found.', 404);
        }

        if ($exception instanceof CustomerContactUserLinkNotFound) {
            return $this->responder->error('CUSTOMER_CONTACT_USER_LINK_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof UserNotFound) {
            return $this->responder->error('USER_NOT_FOUND', 'The requested user was not found.', 404);
        }

        if ($exception instanceof CustomerCodeConflict) {
            return $this->responder->error('CUSTOMER_CODE_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof PortalUserProvisioningFailed) {
            return $this->responder->error('PORTAL_USER_PROVISIONING_FAILED', $exception->getMessage(), 422);
        }

        if (
            $exception instanceof InvalidCustomerValue
            || $exception instanceof InvalidPortalAccessTransition
            || $exception instanceof InactiveCustomerPortalAccess
            || $exception instanceof InactiveContactPortalAccess
            || $exception instanceof DuplicateContactUserLink
            || $exception instanceof InvalidCustomerRoleAssignment
        ) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidIdentityValue || $exception instanceof InvalidArgumentException) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        if ($exception instanceof DomainException || $exception instanceof RuntimeException) {
            return $this->responder->error('VALIDATION_FAILED', 'The request could not be processed.', 422);
        }

        return $this->responder->error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
    }
}
