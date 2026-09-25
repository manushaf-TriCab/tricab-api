<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Somebody tried to review their own work.
 *
 * Maps to 403. Unlike an ownership breach — which answers 404 so the existence
 * of another person's records is not confirmed — there is nothing to conceal
 * here: the person already knows the record is theirs, and telling them plainly
 * is the only way they will go and find a second pair of eyes.
 */
final class SelfReviewNotPermitted extends DomainException
{
    private function __construct(
        string $message,
        public readonly UserId $userId,
    ) {
        parent::__construct($message);
    }

    public static function forUser(UserId $userId): self
    {
        return new self(
            'You cannot review your own onboarding. Ask another administrator to review it.',
            $userId,
        );
    }

    /** Filling the HR or manager part of one's own form (5.4) — the same rule, on the same record. */
    public static function contribution(UserId $userId): self
    {
        return new self(
            'You cannot fill the HR or manager part of your own onboarding. Ask another administrator or your manager.',
            $userId,
        );
    }
}
