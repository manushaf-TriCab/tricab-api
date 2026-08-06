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
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Core\Identity\Application\Exception\PermissionCodeConflict;
use TriCab\Core\Identity\Application\Exception\PermissionNotFound;
use TriCab\Core\Identity\Application\Exception\RoleCodeConflict;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Application\Exception\UserEmailConflict;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Domain\Exception\InactivePermissionAssignment;
use TriCab\Core\Identity\Domain\Exception\InactiveRoleAssignment;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Exception\LastAdminProtection;
use TriCab\Core\Identity\Domain\Exception\SelfLockoutProtection;
use TriCab\Core\Identity\Domain\Exception\SystemPermissionProtected;
use TriCab\Core\Identity\Domain\Exception\SystemRoleProtected;

/**
 * Maps Core user-management application/domain exceptions to safe JSON errors
 * for the admin User/Role/Permission/Audit endpoints.
 */
final class UserManagementErrorMapper
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

        if ($exception instanceof DatabaseUnavailable) {
            return $this->responder->error('DATABASE_UNAVAILABLE', 'The database is temporarily unavailable.', 503);
        }

        if ($exception instanceof UserNotFound) {
            return $this->responder->error('USER_NOT_FOUND', 'The requested user was not found.', 404);
        }

        if ($exception instanceof RoleNotFound) {
            return $this->responder->error('ROLE_NOT_FOUND', 'The requested role was not found.', 404);
        }

        if ($exception instanceof PermissionNotFound) {
            return $this->responder->error('PERMISSION_NOT_FOUND', 'The requested permission was not found.', 404);
        }

        if ($exception instanceof UserEmailConflict) {
            return $this->responder->error('USER_EMAIL_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof RoleCodeConflict) {
            return $this->responder->error('ROLE_CODE_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof PermissionCodeConflict) {
            return $this->responder->error('PERMISSION_CODE_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof LastAdminProtection) {
            return $this->responder->error('LAST_ADMIN_PROTECTION', $exception->getMessage(), 409);
        }

        if ($exception instanceof SelfLockoutProtection) {
            return $this->responder->error('SELF_LOCKOUT_PROTECTION', $exception->getMessage(), 409);
        }

        if ($exception instanceof SystemRoleProtected) {
            return $this->responder->error('SYSTEM_ROLE_PROTECTED', $exception->getMessage(), 409);
        }

        if ($exception instanceof SystemPermissionProtected) {
            return $this->responder->error('SYSTEM_PERMISSION_PROTECTED', $exception->getMessage(), 409);
        }

        if ($exception instanceof InactiveRoleAssignment || $exception instanceof InactivePermissionAssignment) {
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
