<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * Progress naming a slide the published manifest does not contain.
 *
 * Refused rather than ignored. Silently dropping the unknown slide would record
 * a lower `ReachedSlideCount` than the client believes it sent, and the person
 * would be told later that they had not finished a presentation they had sat
 * through — a discrepancy nobody can debug from the row afterwards, because the
 * dropped id is not in it.
 *
 * In practice it means one of two things, and both want a loud answer: the
 * client is out of date with the manifest, or somebody is posting made-up
 * progress. Completion is derived from this list, so a fabricated slide id is
 * an attempt to finish a module without opening it.
 *
 * Maps to 422.
 */
final class UnknownPresentationSlide extends DomainException
{
    /**
     * @param list<string> $slideIds
     */
    public static function forIds(array $slideIds): self
    {
        return new self(sprintf(
            'These slides are not in the published presentation: %s.',
            implode(', ', $slideIds),
        ));
    }
}
