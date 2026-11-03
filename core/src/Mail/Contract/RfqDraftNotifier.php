<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Contract;

use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Application\DTO\MailParty;

/**
 * Tells the people who work RFQs that the reader made a new draft from an email (RFQ mail plan, notifications).
 *
 * Called by {@see \TriCab\Core\Mail\Application\Service\MailDraftAutomation} once, after the draft is made and
 * linked to its thread: never for a follow-up, never for the inbox's "Create draft" button, never in shadow
 * mode (no automation exists then). Best effort: a failure here is logged by the caller and never undoes or
 * retries the draft.
 */
interface RfqDraftNotifier
{
    public function newEmailDraft(CreatedDraft $draft, MailParty $sender): void;
}
