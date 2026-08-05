<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Contract\GuestLoginEligibilityLookup;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Configurable eligibility stub for OTP unit tests.
 */
final class FakeGuestLoginEligibilityLookup implements GuestLoginEligibilityLookup
{
    /** @var array<string, GuestLoginEligibility> */
    private array $byEmail = [];

    public GuestLoginEligibility $default;

    public function __construct(?GuestLoginEligibility $default = null)
    {
        $this->default = $default ?? GuestLoginEligibility::fromFlags(
            userExists: true,
            activeUser: true,
            hasLoginPermission: true,
        );
    }

    public function set(string $email, GuestLoginEligibility $eligibility): void
    {
        $this->byEmail[EmailAddress::fromString($email)->toString()] = $eligibility;
    }

    public function evaluate(EmailAddress $emailAddress): GuestLoginEligibility
    {
        return $this->byEmail[$emailAddress->toString()] ?? $this->default;
    }
}
