<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * The assignment this person asked for is not one of theirs.
 *
 * Work order invariant 4: **not-found and not-yours are indistinguishable to a
 * trainee.** There is one exception for both, it carries no detail, and every
 * write path raises this same one — so a person who guesses another person's
 * assignment id gets the same answer as a person who mistypes their own, and
 * cannot tell from the response which of the two happened.
 *
 * That is why the message names no id. A message saying "assignment
 * 3f2a… belongs to somebody else" would confirm the id exists, which is the
 * whole thing the invariant is there to stop.
 *
 * It also covers a **withdrawn** allocation, and covers it by accident rather
 * than by a check: every self-service query filters `[WithdrawnAtUtc] IS NULL`,
 * so a withdrawn row is not found in the first place. HR has taken the item
 * back; there is nothing to submit against it, and nothing here needed to be
 * written to say so.
 *
 * Maps to 404.
 */
final class OnboardingAssignmentNotFound extends DomainException
{
    public static function forThisPerson(): self
    {
        return new self('No such onboarding item.');
    }
}
