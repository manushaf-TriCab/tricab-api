<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * What an HR action was, as `[audit].[OnboardingAdminEvents].[EventType]`
 * records it.
 *
 * A closed list rather than free text so a report can group on it and a typo
 * in one service cannot invent a category nobody queries for. Phase 4 adds its
 * codes here as each action lands.
 */
final class AdminEventType
{
    public const ALLOCATION_ALLOCATED = 'allocation.allocated';
    public const ALLOCATION_WITHDRAWN = 'allocation.withdrawn';
    public const ALLOCATION_DUE_DATE_CHANGED = 'allocation.due_date_changed';
    /** A whole allocation save that was refused before anything was written. */
    public const ALLOCATION_REFUSED = 'allocation.refused';
    /** A reviewer accepted, sent back, or replied to one queued item (4.3). */
    public const REVIEW_DECIDED = 'review.decided';
    /** A review decision refused before anything was written — self-review, or an item no longer queued. */
    public const REVIEW_REFUSED = 'review.refused';
    /** An induction record was created (4.1), and possibly the GUEST account with it. */
    public const PERSON_CREATED = 'person.created';
    /** Position, department, branch, start date or last day changed. */
    public const PERSON_PROFILE_UPDATED = 'person.profile_updated';
    public const PERSON_INVITED = 'person.invited';
    /** An invitation refused, or one the provider did not accept. */
    public const PERSON_INVITATION_REFUSED = 'person.invitation_refused';
    /**
     * An induction record removed while nothing was recorded against it — or a
     * refusal to, because something was. The account is kept (audit rows name
     * it) and disabled only if onboarding made it.
     */
    public const PERSON_DELETED = 'person.deleted';
    /** A training module, policy or form created, with its first empty draft (4.4). */
    public const CONTENT_ITEM_CREATED = 'content.item_created';
    /** Title, summary or active flag changed. */
    public const CONTENT_ITEM_UPDATED = 'content.item_updated';
    public const CONTENT_DRAFT_CREATED = 'content.draft_created';
    public const CONTENT_DRAFT_SAVED = 'content.draft_saved';
    public const CONTENT_PUBLISHED = 'content.published';
    /** A module, policy or form removed, with its versions, before anybody was ever given it. */
    public const CONTENT_ITEM_DELETED = 'content.item_deleted';
    /** An authoring action refused before anything was written. */
    public const CONTENT_REFUSED = 'content.refused';
    /** One person moved onto a new version because its author said it supersedes work in progress. */
    public const ALLOCATION_REISSUED = 'allocation.reissued';
    /** HR or the named manager filled their part of a form (5.4). */
    public const FORM_CONTRIBUTED = 'form.contributed';
    public const FORM_CONTRIBUTION_REFUSED = 'form.contribution_refused';

    public const ALL = [
        self::ALLOCATION_ALLOCATED,
        self::ALLOCATION_WITHDRAWN,
        self::ALLOCATION_DUE_DATE_CHANGED,
        self::ALLOCATION_REFUSED,
        self::REVIEW_DECIDED,
        self::REVIEW_REFUSED,
        self::PERSON_CREATED,
        self::PERSON_PROFILE_UPDATED,
        self::PERSON_INVITED,
        self::PERSON_INVITATION_REFUSED,
        self::PERSON_DELETED,
        self::CONTENT_ITEM_CREATED,
        self::CONTENT_ITEM_UPDATED,
        self::CONTENT_DRAFT_CREATED,
        self::CONTENT_DRAFT_SAVED,
        self::CONTENT_PUBLISHED,
        self::CONTENT_ITEM_DELETED,
        self::CONTENT_REFUSED,
        self::ALLOCATION_REISSUED,
        self::FORM_CONTRIBUTED,
        self::FORM_CONTRIBUTION_REFUSED,
    ];

    public static function assertKnown(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw InvalidOnboardingValue::forOneOf('AdminEventType', $value, self::ALL);
        }

        return $value;
    }
}
