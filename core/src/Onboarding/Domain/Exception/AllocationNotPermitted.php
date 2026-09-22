<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * This item may not be allocated to this person.
 *
 * Every case here is an HR action being refused, so it maps to 409 rather than
 * 403: the person doing it holds the permission, and the answer is about the
 * state of the thing rather than about who is asking. The reason code is
 * carried separately because the console shows a different remedy for each one
 * and "allocation failed" is not a remedy.
 */
final class AllocationNotPermitted extends DomainException
{
    public const REASON_NOT_PUBLISHED = 'not_published';
    public const REASON_RETIRED = 'retired';
    public const REASON_ALREADY_ALLOCATED = 'already_allocated';
    public const REASON_DEPARTED = 'departed';
    public const REASON_UNAVAILABLE = 'unavailable';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notPublished(): self
    {
        return new self(
            'That version is a draft. Publish it before allocating it to anybody.',
            self::REASON_NOT_PUBLISHED,
        );
    }

    public static function retired(): self
    {
        return new self(
            'That version has been retired. Allocate the current one instead.',
            self::REASON_RETIRED,
        );
    }

    public static function alreadyAllocated(): self
    {
        return new self(
            'This person already has that allocated and has not finished with it.',
            self::REASON_ALREADY_ALLOCATED,
        );
    }

    /**
     * The selection names something with no published, live version — it does
     * not exist, was never published, or every version is retired. One answer
     * for the three: the catalogue HR chose from did not offer it, so the page
     * they are on is out of date and a reload is the remedy either way.
     */
    public static function unavailable(string $itemType, string $itemPublicId): self
    {
        return new self(
            sprintf('That %s (%s) is not available to allocate. Reload the page and choose again.', $itemType, $itemPublicId),
            self::REASON_UNAVAILABLE,
        );
    }

    public static function departed(): self
    {
        return new self(
            'This person has left. Allocating work to a leaver produces evidence dated after their last day.',
            self::REASON_DEPARTED,
        );
    }
}
