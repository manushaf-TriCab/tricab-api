<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Drafts;

use TriCab\Core\Mail\Application\DTO\MessageView;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Contract\RfqFollowupProposer;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * RFQ mail M6.2: a follow-up's content becomes a proposal on the draft that is already open. The envelope is built
 * exactly as for the first email ({@see EmailEnvelopeGatherer}, one pass over body and attachments, GETs only) and
 * handed to {@see RfqReaderService::proposeFromEmail()}, which records the rows as a finished read the editor
 * offers with Accept / Dismiss. Nothing is applied over the rep's edits and no draft is made.
 *
 * **One message, one proposal.** The proposal's id is a hash of the mailbox and the message's internetMessageId,
 * and it is looked up before Exchange is asked for anything, so redeliveries and retries cost nothing.
 */
final class RfqEmailFollowupProposer implements RfqFollowupProposer
{
    private readonly EmailEnvelopeGatherer $gatherer;

    public function __construct(
        private readonly MailStore $store,
        EmailEnvelopeGatherer $gatherer,
        private readonly RfqReaderService $rfqs,
        private readonly string $mailbox,
    ) {
        $this->gatherer = $gatherer;
    }

    /** The proposal id a message always gets: 32 hex characters from the mailbox and internetMessageId. */
    public static function proposalIdFor(string $mailbox, string $internetMessageId): string
    {
        return substr(hash('sha256', 'rfq-mail-followup|' . strtolower(trim($mailbox)) . '|' . trim($internetMessageId)), 0, 32);
    }

    public function propose(string $threadId, string $messageId, string $draftPublicId): string
    {
        $draftId = StoreProjection::draftId($draftPublicId);
        $thread = $this->store->thread($threadId);
        $ref = $this->store->messageRef($messageId);
        $message = null;
        foreach ($thread === null ? [] : $thread->messages as $candidate) {
            if ($candidate->id === $messageId) {
                $message = $candidate;
            }
        }
        if ($draftId === null || $thread === null || $ref === null || !$message instanceof MessageView) {
            return self::NO_DRAFT;
        }
        $proposalId = self::proposalIdFor($ref->mailboxAddress, $message->internetMessageId);
        $subject = trim($thread->summary->subject) !== '' ? trim($thread->summary->subject) : '(no subject)';
        $info = [
            'sender_name' => $message->from->name,
            'sender_address' => $message->from->address,
            'received_at' => $message->receivedAt->format('c'),
            'subject' => $subject,
            'mail_message_id' => $messageId,
            'mail_thread_id' => $threadId,
            'internet_message_id' => $message->internetMessageId,
        ];

        try {
            if ($this->rfqs->hasEmailProposal($draftId, $proposalId)) {
                return self::EXISTS;
            }
            [, , $envelope] = $this->gatherer->gather($ref->mailboxAddress, $ref->graphMessageId, $message, $subject);
            try {
                $result = $this->rfqs->proposeFromEmail($draftId, $proposalId, $this->mailbox, $envelope, $info, CorrelationId::generate());
            } catch (RfqParserFailure $failure) {
                // A file the parser refuses (not an outage) must not cost the email: the body alone is still a proposal.
                if ($failure->isRetryable() || $envelope['documents'] === []) {
                    throw $failure;
                }
                $result = $this->rfqs->proposeFromEmail($draftId, $proposalId, $this->mailbox, ['documents' => []] + $envelope, $info, CorrelationId::generate());
            }
        } catch (MailNotFound $failure) {
            throw new MailUnavailable('The follow-up is no longer in the mailbox.', MailUnavailable::DRAFTS, $failure);
        } catch (RfqParserFailure $failure) {
            throw new MailUnavailable('The RFQ parser could not read the follow-up: ' . $failure->getMessage(), MailUnavailable::DRAFTS, $failure);
        } catch (InvalidRfqValue|RfqStoreUnavailable $failure) {
            throw new MailUnavailable('The follow-up proposal could not be recorded: ' . $failure->getMessage(), MailUnavailable::DRAFTS, $failure);
        }

        return match ($result) {
            'proposed' => self::PROPOSED,
            'empty' => self::EMPTY,
            'exists' => self::EXISTS,
            default => self::NO_DRAFT,
        };
    }
}
