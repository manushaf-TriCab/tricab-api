<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Infrastructure;

use TriCab\Core\Mail\Contract\RfqFollowupProposer;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;

/** The binding while drafts are off: nothing is proposed. See {@see RfqFollowupProposer}. */
final class UnavailableRfqFollowupProposer implements RfqFollowupProposer
{
    public function propose(string $threadId, string $messageId, string $draftPublicId): string
    {
        throw new MailUnavailable('Email follow-up proposals are not switched on.', MailUnavailable::DRAFTS);
    }
}
