<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Contract;

use TriCab\Core\Mail\Domain\Exception\MailUnavailable;

/**
 * Turns a follow-up message into a proposal on the draft its conversation already has (RFQ mail M6.2): the
 * message is read the way the first email was, and its rows are stored for the rep to accept or dismiss. Nothing is
 * applied to the draft and no second draft is made.
 *
 * Must be idempotent per message: a retry, an overlapping pass or Graph redelivering records one proposal.
 */
interface RfqFollowupProposer
{
    public const PROPOSED = 'proposed';
    public const EMPTY = 'empty';
    public const EXISTS = 'exists';
    public const NO_DRAFT = 'no_draft';

    /**
     * @param string $draftPublicId the draft the thread is linked to
     *
     * @return self::PROPOSED|self::EMPTY|self::EXISTS|self::NO_DRAFT
     *
     * @throws MailUnavailable when the message could not be read or parsed now; nothing was recorded
     */
    public function propose(string $threadId, string $messageId, string $draftPublicId): string;
}
