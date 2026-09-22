<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * An attempt asked for when the assessment is not open to this person.
 *
 * Three ways to get here, all refused with the same exception and different
 * messages, because the person needs to know which one it is and nobody else
 * needs to know it happened:
 *
 *  - the module has no questions — there is nothing to attempt;
 *  - the presentation is not finished — the questions are about slides this
 *    person has not been shown, so sitting the quiz first turns the module into
 *    a guessing game whose evidence says they were trained;
 *  - the item is already complete — a passed assessment closes it, and re-sitting
 *    would produce a second attempt whose result cannot change anything.
 *
 * Maps to 409: the request makes sense and refers to something real, but not to
 * something that can happen yet.
 */
final class AssessmentNotAvailable extends DomainException
{
    public static function moduleHasNoQuestions(): self
    {
        return new self('This module has no assessment.');
    }

    public static function presentationNotFinished(): self
    {
        return new self('Work through the whole presentation before starting the assessment.');
    }

    public static function alreadyComplete(): self
    {
        return new self('This module is already complete.');
    }
}
