<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * An authoring action the content's current state does not admit (4.4).
 *
 * Maps to 409 with the reason in the code, because each has a different remedy:
 * a published version is changed by starting a new draft, a second draft is
 * finished or published first, a taken slug is renamed, and an incomplete draft
 * is completed. "Cannot save" is none of them.
 */
final class ContentAuthoringNotPermitted extends DomainException
{
    public const REASON_VERSION_PUBLISHED = 'version_published';
    public const REASON_DRAFT_EXISTS = 'draft_exists';
    public const REASON_SLUG_TAKEN = 'slug_taken';
    public const REASON_NOT_PUBLISHABLE = 'not_publishable';

    /**
     * @param list<string> $problems what the author has to fix, in the order they appear on the page
     */
    private function __construct(string $message, public readonly string $reason, public readonly array $problems = [])
    {
        parent::__construct($message);
    }

    /**
     * A published version is frozen. People have read it, acknowledged it and
     * been graded against it, and the evidence points at this row.
     */
    public static function versionPublished(): self
    {
        return new self('This version is published and cannot be changed. Start a new draft to change it.', self::REASON_VERSION_PUBLISHED);
    }

    public static function draftExists(): self
    {
        return new self('This item already has a draft. Finish or publish it before starting another.', self::REASON_DRAFT_EXISTS);
    }

    public static function slugTaken(string $slug): self
    {
        return new self(sprintf('The short name "%s" is already used by another item of this type.', $slug), self::REASON_SLUG_TAKEN);
    }

    /**
     * @param list<string> $problems
     */
    public static function notPublishable(array $problems): self
    {
        return new self('This draft cannot be published yet: ' . implode(' ', $problems), self::REASON_NOT_PUBLISHABLE, $problems);
    }
}
