<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * An invitation this person cannot usefully be sent.
 *
 * Maps to 409, with the reason in the code: each has a different remedy, and
 * "invitation failed" is none of them.
 */
final class InvitationNotPermitted extends DomainException
{
    public const REASON_DEPARTED = 'departed';
    public const REASON_CANNOT_SIGN_IN = 'cannot_sign_in';
    public const REASON_TOO_SOON = 'too_soon';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function departed(): self
    {
        return new self('This person has left. An invitation would send them to an induction they can no longer complete.', self::REASON_DEPARTED);
    }

    /**
     * Their platform account is pending or disabled, or holds no role that lets
     * it sign in. An invitation would bring them to a sign-in page that refuses
     * them; the account is fixed in Admin → User Management.
     */
    public static function cannotSignIn(): self
    {
        return new self('This person’s account cannot sign in yet. Activate it in User Management before inviting them.', self::REASON_CANNOT_SIGN_IN);
    }

    public static function tooSoon(int $seconds): self
    {
        return new self(sprintf('An invitation was sent less than %d seconds ago. Wait a moment before sending another.', $seconds), self::REASON_TOO_SOON);
    }
}
