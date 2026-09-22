<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * A document that is not this person's, has been deleted, or never existed.
 *
 * One exception for all three, for the reason {@see OnboardingAssignmentNotFound}
 * gives: a distinguishable "exists, but not yours" turns a document id into an
 * oracle, and these are payslip PINs and medical questionnaires.
 *
 * It also covers the case where the row is there and the bytes are not. That is
 * an operational fault rather than an authorization one, and it is deliberately
 * NOT reported as a 500: a missing blob tells whoever is probing that the
 * reference exists, which is the same disclosure by a slower route. The fault is
 * visible where it should be — in the logs, and in the audit row that records an
 * authorized read that returned nothing.
 *
 * Maps to 404.
 */
final class OnboardingDocumentNotFound extends DomainException
{
    public static function forThisPerson(): self
    {
        return new self('No such document.');
    }
}
