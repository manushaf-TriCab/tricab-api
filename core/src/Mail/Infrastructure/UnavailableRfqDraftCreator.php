<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Infrastructure;

use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;

/**
 * The binding until RFQ M2 can parse an email: "Create draft" refuses and records nothing, so the
 * thread stays open. See {@see RfqDraftCreator}.
 */
final class UnavailableRfqDraftCreator implements RfqDraftCreator
{
    public const REASON = 'Creating an RFQ draft from an email is not available yet: the RFQ parser cannot read emails until RFQ M2 is built. Nothing was changed.';

    public function createFromThread(MailActor $actor, string $threadId, string $subject): CreatedDraft
    {
        throw new MailUnavailable(self::REASON, MailUnavailable::DRAFTS);
    }
}
