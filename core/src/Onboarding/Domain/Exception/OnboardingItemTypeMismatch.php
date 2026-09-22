<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * A write aimed at the wrong kind of item — acknowledging a training module,
 * posting slide progress against a form.
 *
 * This is a client defect rather than an attack, and it is reported as one: the
 * person owns the assignment, so hiding its existence buys nothing, and a
 * caller that has confused two routes needs to be told which one it is on.
 * Contrast {@see OnboardingAssignmentNotFound}, which deliberately says nothing
 * because there the caller may not own the row at all.
 *
 * It is checked rather than assumed because the three item types share one
 * table and one public-id space. `CK_onboarding_Assignments_ItemMatchesType`
 * holds the row's own consistency; nothing in the schema stops a handler
 * reading a policy row and writing an attempt against it.
 *
 * Maps to 409: the request is well-formed and refers to something real, but it
 * conflicts with what that something is.
 */
final class OnboardingItemTypeMismatch extends DomainException
{
    public static function expected(string $expected, string $actual): self
    {
        return new self(sprintf(
            'This onboarding item is a %s, not a %s.',
            $actual,
            $expected,
        ));
    }
}
