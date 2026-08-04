<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Validated body for POST /api/v1/admin/users.
 */
final readonly class CreateUserRequest
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        public string $emailAddress,
        public string $displayName,
        public string $status,
        public array $roles,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $emailAddress = $body['emailAddress'] ?? null;
        if (!is_string($emailAddress) || trim($emailAddress) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'emailAddress is required.');
        }

        $displayName = $body['displayName'] ?? null;
        if (!is_string($displayName) || trim($displayName) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'displayName is required.');
        }

        $status = $body['status'] ?? UserStatus::ACTIVE;
        if (!is_string($status) || trim($status) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'status must be a non-empty string.');
        }

        $roles = self::readRoles($body);

        return new self(
            emailAddress: trim($emailAddress),
            displayName: trim($displayName),
            status: trim($status),
            roles: $roles,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private static function readRoles(array $body): array
    {
        $rawRoles = $body['roles'] ?? [];
        if (!is_array($rawRoles)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'roles must be an array of role codes.');
        }

        $roles = [];
        foreach ($rawRoles as $role) {
            if (!is_string($role) || trim($role) === '') {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'roles must contain non-empty strings.');
            }

            $roles[] = trim($role);
        }

        return $roles;
    }
}
