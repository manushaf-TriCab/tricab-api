<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Contract;

use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;

/**
 * Makes an RFQ draft from a mail thread: "Create draft" on an uncertain thread (plan I4.1), the same
 * service RFQ M6.1 will use for the reader's own `rfq` mail.
 *
 * No such service exists today. `RfqReviewService::create` takes a sealed parse of pasted text, and
 * the parser does not read an email until RFQ M2 (the envelope) is built. Until then the container
 * binds an implementation that refuses with {@see MailUnavailable}.
 */
interface RfqDraftCreator
{
    /**
     * Must be idempotent on `$threadId`: a second call for the same thread returns the draft the first
     * made. The review service checks the thread is still open first, but two reviewers can race.
     *
     * @throws MailUnavailable (what = drafts) when drafts cannot be made now; nothing was created
     */
    public function createFromThread(MailActor $actor, string $threadId, string $subject): CreatedDraft;
}
