<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;
use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;

/**
 * Raised when guest OTP must not be issued for an email.
 * Public clients must only see a single safe "not registered" message;
 * $reasonCode is for server-side logging only.
 */
final class GuestLoginNotEligible extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'This email is not registered for TriCab access.',
    ) {
        parent::__construct($message);
    }

    public static function fromEligibility(GuestLoginEligibility $eligibility): self
    {
        return new self($eligibility->rejectionReasonCode());
    }
}
