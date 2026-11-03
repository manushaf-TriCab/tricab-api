<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewLink;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Contract\RfqDraftNotifier;
use TriCab\Core\Mail\Contract\RfqFollowupProposer;
use TriCab\Core\Mail\Domain\Exception\MailActionBlocked;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Shared\Time\Clock;
use Throwable;

/**
 * What the reader does with a message the classifier has labelled, once drafts are switched on (RFQ plan M6.1,
 * M6.2). The reader only calls this when `Settings::isRfqMailDraftsOn()`; in shadow mode it does not exist.
 *
 * - `rfq` makes a draft through {@see RfqDraftCreator} (the upload's intake) and links it, unless the thread was
 *   already actioned: a thread that already has a draft (a second RFQ-labelled message in the same
 *   conversation) joins that draft as a follow-up instead, and an ignored thread is left alone.
 * - `rfq_followup` joins the message to the draft its conversation already has, and (with a
 *   {@see RfqFollowupProposer}) the message's rows become a proposal on that draft: nothing is applied over a rep's
 *   edits, no second draft is made, and no bell notice is sent. With no draft there is nothing to join, and the
 *   thread stays open for a person.
 * - `uncertain` and `not_rfq` do nothing here: uncertain waits in the review list for "Create draft" or
 *   "Ignore", not_rfq is recorded only.
 * - A message the classifier flagged for text aimed at an assistant (`injection`) never gets an automatic
 *   draft: a person reads it first.
 *
 * **Safe to repeat.** The draft's id comes from the message, so a second attempt (a retry after a failure, an
 * overlapping pass, Graph redelivering) finds the first's draft; the thread's link and the follow-up link are
 * each written once. Failures are {@see MailUnavailable}, which the caller queues for another try.
 */
final class MailDraftAutomation
{
    public const CREATED = 'created';
    public const FOLLOWUP = 'followup';
    public const NOTHING = 'nothing';

    public function __construct(
        private readonly MailStore $store,
        private readonly RfqDraftCreator $drafts,
        private readonly string $mailbox,
        private readonly Clock $clock,
        private readonly ?RfqDraftNotifier $notifier = null,
        private readonly ?RfqFollowupProposer $proposer = null,
    ) {
    }

    /**
     * @return self::CREATED|self::FOLLOWUP|self::NOTHING
     *
     * @throws MailUnavailable when the draft could not be made or linked now; nothing was half-done that a retry cannot finish
     */
    public function apply(string $threadId, string $messageId, string $label, bool $injectionSuspected): string
    {
        if ($label !== V::LABEL_RFQ && $label !== V::LABEL_RFQ_FOLLOWUP) {
            return self::NOTHING;
        }
        $thread = $this->store->thread($threadId);
        if ($thread === null) {
            return self::NOTHING;
        }
        $by = new MailParty('RFQ mail intake', $this->mailbox);
        $draft = null;
        foreach ($thread->links as $link) {
            if ($link->kind === V::LINK_RFQ_DRAFT) {
                $draft = $link;
                break;
            }
        }

        if ($draft !== null) {
            if ($draft->messageId === $messageId) {
                // This very message made the draft (a retry or a redelivery): it is not its own follow-up.
                return self::NOTHING;
            }
            // The conversation has its draft: this message belongs to it, whatever the label says.
            $linked = $this->store->recordFollowup($threadId, $messageId, new NewLink($draft->publicId, $draft->reference, $draft->title, null, $this->clock->now()), $by);
            // Whether or not this pass linked it: a retry after a failed proposal finds the link already there, and
            // the proposer is idempotent per message (it looks before it fetches).
            $this->proposer?->propose($threadId, $messageId, $draft->publicId);

            return $linked ? self::FOLLOWUP : self::NOTHING;
        }
        if ($label === V::LABEL_RFQ_FOLLOWUP || $injectionSuspected || $thread->summary->reviewState !== V::STATE_OPEN) {
            return self::NOTHING;
        }

        $made = $this->drafts->createFromThread(new MailActor(0, $by->name, $by->address, []), $threadId, $thread->summary->subject, $messageId);
        try {
            $this->store->recordDraft($threadId, new NewLink($made->publicId, $made->reference, $made->title, null, $this->clock->now(), $messageId), $by);
        } catch (MailActionBlocked) {
            // Someone actioned the thread between the read and this write (a reviewer's "Create draft" or
            // "Ignore", or a racing pass). The draft's id comes from the message, so a creator that ran twice
            // made the same draft once.
            return self::NOTHING;
        }

        if ($this->notifier !== null) {
            try {
                $this->notifier->newEmailDraft($made, $thread->summary->from);
            } catch (Throwable $failure) {
                // The draft is made and linked; telling people is best effort and must not cost the pass.
                error_log('RFQ mail notification not sent: ' . $failure->getMessage());
            }
        }

        return self::CREATED;
    }
}
