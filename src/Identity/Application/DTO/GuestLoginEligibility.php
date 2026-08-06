<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Diagnostic eligibility result for guest OTP login.
 * Never serialize this object to public API responses.
 *
 * Authentication only: registered active user with ACCOUNT.LOGIN.
 * Customer/contact linkage is enforced later for customer-scoped resources.
 *
 * Business-approved behaviour: ineligible emails are told they are not registered
 * (account-enumeration risk accepted). Use rejectionReasonCode() for safe server logs only.
 */
final readonly class GuestLoginEligibility
{
    public const REASON_USER_NOT_FOUND = 'user_not_found';
    public const REASON_USER_INACTIVE = 'user_inactive';
    public const REASON_MISSING_LOGIN_PERMISSION = 'missing_login_permission';
    public const REASON_ELIGIBLE = 'eligible';

    public function __construct(
        public bool $userExists,
        public bool $activeUser,
        public bool $hasLoginPermission,
        public bool $eligible,
    ) {
    }

    public static function notFound(): self
    {
        return new self(
            userExists: false,
            activeUser: false,
            hasLoginPermission: false,
            eligible: false,
        );
    }

    public static function fromFlags(
        bool $userExists,
        bool $activeUser,
        bool $hasLoginPermission,
    ): self {
        $eligible = $userExists && $activeUser && $hasLoginPermission;

        return new self(
            userExists: $userExists,
            activeUser: $activeUser,
            hasLoginPermission: $hasLoginPermission,
            eligible: $eligible,
        );
    }

    /**
     * Safe internal reason for logging — never return to clients.
     */
    public function rejectionReasonCode(): string
    {
        if ($this->eligible) {
            return self::REASON_ELIGIBLE;
        }

        if (!$this->userExists) {
            return self::REASON_USER_NOT_FOUND;
        }

        if (!$this->activeUser) {
            return self::REASON_USER_INACTIVE;
        }

        return self::REASON_MISSING_LOGIN_PERMISSION;
    }
}
