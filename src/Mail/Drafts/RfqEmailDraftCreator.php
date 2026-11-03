<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Drafts;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Mail\Application\DTO\AttachmentView;
use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Application\DTO\MessageView;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * The one way an email becomes a Quotegen draft (RFQ M6.1, inbox I4.1): the reader's own `rfq` mail and the
 * inbox's "Create draft" on an uncertain conversation both end here, and this ends in
 * {@see RfqReaderService::createFromEmail()}, the intake the upload path uses.
 *
 * The parser reads the whole email in one pass, as an `rfq-mail-envelope.v1` built from Exchange now (nothing is
 * kept in `[mail]`): body, forwarded chain, inline images and the attachments it can read. The draft keeps the
 * body as text (`email-body.txt`) and those attachments. The draft is owned by, assigned to and
 * attributed to the mailbox's own platform user (melbourne@, an ordinary individual account), in the branch
 * `RFQ_MAIL_DRAFT_BRANCH`; whoever clicked "Create draft" is recorded in `email_meta` and the thread's
 * activity. Anyone who can work RFQs can work it.
 *
 * **One message, one draft.** The draft's id is a hash of the mailbox and the message's internetMessageId, so
 * every caller (the reader, a retry, an overlapping pass, a reviewer's click) arrives at the same primary key;
 * see {@see RfqReaderService::createFromEmail()}. A thread that already has a draft returns it unchanged.
 */
final class RfqEmailDraftCreator implements RfqDraftCreator
{
    private readonly EmailEnvelopeGatherer $gatherer;

    private ?RfqActor $actor = null;

    private string $mailboxEmail = '';

    public function __construct(
        private readonly MailStore $store,
        private readonly MailBodyFetcher $fetcher,
        private readonly RfqReaderService $rfqs,
        private readonly UserRepository $users,
        private readonly string $mailbox,
        private readonly string $branch,
    ) {
        $this->gatherer = new EmailEnvelopeGatherer($fetcher);
    }

    /** The draft id a message always gets: 32 hex characters from the mailbox and internetMessageId. */
    public static function draftIdFor(string $mailbox, string $internetMessageId): string
    {
        return substr(hash('sha256', 'rfq-mail-draft|' . strtolower(trim($mailbox)) . '|' . trim($internetMessageId)), 0, 32);
    }

    public function createFromThread(MailActor $actor, string $threadId, string $subject, ?string $messageId = null): CreatedDraft
    {
        $thread = $this->store->thread($threadId) ?? throw new MailNotFound('That conversation is no longer in the inbox.');
        foreach ($thread->links as $link) {
            if ($link->kind === V::LINK_RFQ_DRAFT) {
                return new CreatedDraft($link->publicId, $link->reference, $link->title);
            }
        }

        $wanted = $messageId ?? $thread->assessment->messageId;
        $message = null;
        foreach ($thread->messages as $candidate) {
            if ($candidate->id === $wanted) {
                $message = $candidate;
            }
        }
        $ref = $message === null ? null : $this->store->messageRef($message->id);
        if ($message === null || $ref === null) {
            throw new MailUnavailable('The message to make a draft from could not be found.', MailUnavailable::DRAFTS);
        }

        $rfqActor = $this->rfqActor();
        $draftId = self::draftIdFor($ref->mailboxAddress, $message->internetMessageId);
        $title = trim($thread->summary->subject) !== '' ? trim($thread->summary->subject) : '(no subject)';
        $label = mb_substr("Email \u{2013} " . $title, 0, 200);

        [$files, $notes, $envelope] = $this->gather($ref->mailboxAddress, $ref->graphMessageId, $message, $title);
        $meta = [
            'internet_message_id' => $message->internetMessageId,
            'graph_message_id' => $ref->graphMessageId,
            'mailbox' => $ref->mailboxAddress,
            'mail_thread_id' => $threadId,
            'mail_message_id' => $message->id,
            'subject' => $title,
            'original_sender' => ['name' => $message->from->name, 'address' => $message->from->address],
            'received_at' => $message->receivedAt->format('c'),
            'attachments' => array_map(static fn (AttachmentView $a): array => ['name' => $a->fileName, 'type' => $a->mimeType, 'size' => $a->sizeBytes], $message->attachments),
        ];
        if ($notes !== []) {
            $meta['attachments_not_read'] = $notes;
        }
        if ($actor->userId > 0) {
            $meta['draft_requested_by'] = $actor->email;
        }

        try {
            $made = $this->make($rfqActor, $draftId, $label, $files, $meta, $envelope);
        } catch (RfqParserFailure $failure) {
            // A file the parser refuses (not a retryable outage) must not cost the whole email: the body alone is a draft.
            if ($failure->isRetryable() || count($files) < 2) {
                throw new MailUnavailable('The RFQ parser could not read the email: ' . $failure->getMessage(), MailUnavailable::DRAFTS, $failure);
            }
            $meta['attachments_not_read'] = array_merge($notes, ['the parser refused the attachments: ' . $failure->getMessage()]);
            try {
                $made = $this->make($rfqActor, $draftId, $label, [$files[0]], $meta, ['documents' => []] + $envelope);
            } catch (RfqParserFailure $again) {
                throw new MailUnavailable('The RFQ parser could not read the email: ' . $again->getMessage(), MailUnavailable::DRAFTS, $again);
            }
        }

        return new CreatedDraft($made, StoreProjection::reference($draftId), $title);
    }

    /**
     * @param list<array{fileName: string, contentType: string, bytes: string}> $files
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $envelope
     *
     * @throws RfqParserFailure
     * @throws MailUnavailable
     */
    private function make(RfqActor $actor, string $draftId, string $label, array $files, array $meta, array $envelope): string
    {
        try {
            return $this->rfqs->createFromEmail($actor, $this->mailboxEmail, $draftId, $label, $files, $meta, CorrelationId::generate(), $envelope)['publicId'];
        } catch (RfqActionForbidden $failure) {
            throw new MailUnavailable($this->mailbox . ' may not create RFQs (it needs RFQ.DRAFT.CREATE).', MailUnavailable::DRAFTS, $failure);
        } catch (InvalidRfqValue|RfqStoreUnavailable $failure) {
            throw new MailUnavailable('The RFQ draft could not be made: ' . $failure->getMessage(), MailUnavailable::DRAFTS, $failure);
        }
    }

    /**
     * @return array{0: list<array{fileName: string, contentType: string, bytes: string}>, 1: list<string>, 2: array<string, mixed>} the kept files, what was left out, the envelope
     */
    private function gather(string $mailbox, string $graphMessageId, MessageView $message, string $subject): array
    {
        return $this->gatherer->gather($mailbox, $graphMessageId, $message, $subject);
    }

    /** The mailbox's own platform user, as an RFQ actor in the draft branch. */
    private function rfqActor(): RfqActor
    {
        if ($this->actor !== null) {
            return $this->actor;
        }
        $user = $this->users->findByEmail(EmailAddress::fromString($this->mailbox));
        if ($user === null) {
            throw new MailUnavailable($this->mailbox . ' is not a platform user, so there is nobody to own the draft.', MailUnavailable::DRAFTS);
        }
        $platform = AuthenticatedUser::fromUser($user);
        $this->mailboxEmail = $platform->emailAddress;

        return $this->actor = new RfqActor($platform->userId, $platform->publicId, $platform->permissions, $this->branch !== '' ? $this->branch : null);
    }
}
