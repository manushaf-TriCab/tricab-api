<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * A payroll or restricted-HR form version cannot be published until every field
 * in its schema has been classified.
 *
 * This is the guard that closes the one fail-open gap in the classification
 * design. Without it an unclassified field is written to `[onboarding]` in
 * clear, and nothing can tell "nobody has classified this" from "somebody
 * decided it is ordinary".
 *
 * Maps to 409, and names the fields, because "classification incomplete" sends
 * somebody to compare two lists by hand.
 */
final class FormVersionNotClassified extends DomainException
{
    /**
     * @param list<string> $fieldPaths
     */
    private function __construct(string $message, public readonly array $fieldPaths)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $fieldPaths
     */
    public static function forFields(array $fieldPaths): self
    {
        return new self(
            sprintf(
                'This form holds payroll or restricted data, so every field must be classified before it can be published. Not yet classified: %s.',
                implode(', ', $fieldPaths),
            ),
            $fieldPaths,
        );
    }
}
