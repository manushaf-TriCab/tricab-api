<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Contract;

use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;

/**
 * Makes an RFQ draft from a mail message: "Create draft" on an uncertain thread (plan I4.1) and the reader's
 * own `rfq` mail (RFQ M6.1) go through the same implementation, which goes through the same intake as an
 * upload (`RfqReaderService`).
 *
 * While drafts are off (`RFQ_MAIL_MODE` is not `live`, or `RFQ_MAIL_DRAFTS_ENABLED` is not true) the container
 * binds an implementation that refuses with {@see MailUnavailable}, which is what "Create draft" has answered
 * since the inbox shipped.
 */
interface RfqDraftCreator
{
    /**
     * Must be idempotent: a second call for the same message (and, with no `$messageId`, the same thread)
     * returns the draft the first made, even when the first call died half-way or two callers raced. The review
     * service checks the thread is still open first, but two reviewers can race.
     *
     * @param string|null $messageId our message id; null is the message the thread's latest assessment is for
     *
     * @throws MailUnavailable (what = drafts) when drafts cannot be made now; nothing was created
     */
    public function createFromThread(MailActor $actor, string $threadId, string $subject, ?string $messageId = null): CreatedDraft;
}
