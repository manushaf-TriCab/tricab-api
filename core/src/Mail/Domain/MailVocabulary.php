<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain;

/**
 * The words the review contract uses, spelled as `tricab-web/src/api/mail/types.ts`
 * spells them. A test pins these lists to that file's names so the two cannot drift.
 */
final class MailVocabulary
{
    public const LABEL_RFQ = 'rfq';
    public const LABEL_RFQ_FOLLOWUP = 'rfq_followup';
    public const LABEL_NOT_RFQ = 'not_rfq';
    public const LABEL_UNCERTAIN = 'uncertain';

    /** @var list<string> */
    public const LABELS = [self::LABEL_RFQ, self::LABEL_RFQ_FOLLOWUP, self::LABEL_NOT_RFQ, self::LABEL_UNCERTAIN];

    /**
     * `all`, `unmarked` and `marked` are not labels; `skipped` is the pre-filter. `unmarked` is the labelling
     * queue: threads nobody has confirmed or corrected yet, skipped ones included (they only lack a human label).
     *
     * @var list<string>
     */
    public const QUEUES = ['all', 'uncertain', 'rfq', 'rfq_followup', 'not_rfq', 'skipped', 'marked', 'unmarked'];

    public const STATE_OPEN = 'open';
    public const STATE_DRAFTED = 'drafted';
    public const STATE_IGNORED = 'ignored';

    /** @var list<string> */
    public const REVIEW_STATES = [self::STATE_OPEN, self::STATE_DRAFTED, self::STATE_IGNORED];

    public const ACTION_CONFIRM = 'confirm_label';
    public const ACTION_CORRECT = 'correct_label';
    public const ACTION_CREATE_DRAFT = 'create_draft';
    public const ACTION_IGNORE = 'ignore';
    public const ACTION_MARK_READ = 'mark_read';
    public const ACTION_MARK_UNREAD = 'mark_unread';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_CONFIRM,
        self::ACTION_CORRECT,
        self::ACTION_CREATE_DRAFT,
        self::ACTION_IGNORE,
        self::ACTION_MARK_READ,
        self::ACTION_MARK_UNREAD,
    ];

    public const BLOCKER_UNDECIDED_LABEL = 'undecided_label';
    public const BLOCKER_INJECTION = 'injection';
    public const BLOCKER_NOT_UNCERTAIN = 'not_uncertain';
    public const BLOCKER_ALREADY_ACTIONED = 'already_actioned';

    /** @var list<string> */
    public const BLOCKERS = [
        self::BLOCKER_UNDECIDED_LABEL,
        self::BLOCKER_INJECTION,
        self::BLOCKER_NOT_UNCERTAIN,
        self::BLOCKER_ALREADY_ACTIONED,
    ];

    public const ACTIVITY_RECEIVED = 'received';
    public const ACTIVITY_CLASSIFIED = 'classified';
    public const ACTIVITY_SKIPPED = 'skipped';
    public const ACTIVITY_LABEL_CONFIRMED = 'label_confirmed';
    public const ACTIVITY_LABEL_CORRECTED = 'label_corrected';
    public const ACTIVITY_DRAFT_CREATED = 'draft_created';
    public const ACTIVITY_IGNORED = 'ignored';
    public const ACTIVITY_FOLLOWUP_LINKED = 'followup_linked';

    public const LINK_RFQ_DRAFT = 'rfq_draft';
    public const LINK_RFQ_FOLLOWUP = 'rfq_followup';

    /** @var list<string> What a link on a thread can be: the draft made from it, or a follow-up joined to that draft. */
    public const LINK_KINDS = [self::LINK_RFQ_DRAFT, self::LINK_RFQ_FOLLOWUP];

    /** @var list<string> */
    public const ACTIVITY_KINDS = [
        self::ACTIVITY_RECEIVED,
        self::ACTIVITY_CLASSIFIED,
        self::ACTIVITY_SKIPPED,
        self::ACTIVITY_LABEL_CONFIRMED,
        self::ACTIVITY_LABEL_CORRECTED,
        self::ACTIVITY_DRAFT_CREATED,
        self::ACTIVITY_IGNORED,
        self::ACTIVITY_FOLLOWUP_LINKED,
    ];

    /** @var list<string> */
    public const SORTS = ['latest', 'oldest', 'sender', 'confidence'];

    /** @var list<string> */
    public const ATTACHMENT_FILTERS = ['any', 'with', 'without'];

    /** @var list<string> */
    public const CONNECTIONS = ['connected', 'paused', 'error'];

    public const MAX_PAGE_SIZE = 100;

    public const DEFAULT_PAGE_SIZE = 25;

    public const MAX_NOTE_LENGTH = 1000;

    public static function isLabel(string $value): bool
    {
        return in_array($value, self::LABELS, true);
    }

    /** Short words for activity lines. */
    public static function labelWord(string $label): string
    {
        return match ($label) {
            self::LABEL_RFQ => 'RFQ',
            self::LABEL_RFQ_FOLLOWUP => 'follow-up',
            self::LABEL_NOT_RFQ => 'not an RFQ',
            default => 'uncertain',
        };
    }
}
