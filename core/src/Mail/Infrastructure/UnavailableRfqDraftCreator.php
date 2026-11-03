<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Infrastructure;

use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;

/**
 * The binding while drafts are off (the default): "Create draft" refuses and records nothing, so the
 * thread stays open. See {@see RfqDraftCreator}.
 */
final class UnavailableRfqDraftCreator implements RfqDraftCreator
{
    public const REASON = 'Creating an RFQ draft from an email is not switched on yet. Nothing was changed.';

    public function createFromThread(MailActor $actor, string $threadId, string $subject, ?string $messageId = null): CreatedDraft
    {
        throw new MailUnavailable(self::REASON, MailUnavailable::DRAFTS);
    }
}
