<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * An induction record that cannot be deleted, because something is recorded
 * against it.
 *
 * Progress, attempts, acknowledgements, form drafts and submissions and
 * documents are evidence, several of them behind triggers that refuse
 * DELETE outright. A person who has done anything is recorded as having left
 * — a last day — never removed; the source deleted leavers and lost every
 * record with them. Maps to 409 `ONBOARDING_PERSON_HAS_RECORDED_WORK`.
 */
final class PersonDeletionNotPermitted extends DomainException
{
    /**
     * @param array<string, int> $recordedWork kind => count, e.g. `acknowledgements => 2`
     */
    private function __construct(string $message, public readonly array $recordedWork)
    {
        parent::__construct($message);
    }

    /**
     * @param array<string, int> $recordedWork
     */
    public static function hasRecordedWork(array $recordedWork): self
    {
        $parts = [];
        foreach ($recordedWork as $kind => $count) {
            $parts[] = sprintf('%d %s', $count, str_replace('_', ' ', $kind));
        }

        return new self(
            'Work is recorded against this person (' . implode(', ', $parts) . '), so they cannot be deleted. Record their last day instead.',
            $recordedWork,
        );
    }
}
