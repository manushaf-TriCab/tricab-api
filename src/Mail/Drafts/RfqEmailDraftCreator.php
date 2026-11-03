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
use TriCab\Core\Mail\Domain\Exception\MailFailure;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Domain\MailVocabulary as V;
use TriCab\Core\Mail\Intake\HtmlToText;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * The one way an email becomes a Quotegen draft (RFQ M6.1, inbox I4.1): the reader's own `rfq` mail and the
 * inbox's "Create draft" on an uncertain conversation both end here, and this ends in
 * {@see RfqReaderService::createFromEmail()}, the intake the upload path uses.
 *
 * The draft's documents are the email's body, as text (`email-body.txt`), and every attachment the parser can
 * read, fetched from Exchange now (nothing is kept in `[mail]`). The draft is owned by, assigned to and
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
    /** What the parser reads (`rfq_parser/intake.py`); anything else on an email is left on the email. */
    private const READABLE = ['xlsx', 'xlsm', 'xls', 'docx', 'pdf', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'webp'];

    private const BODY_FILE = 'email-body.txt';

    private const MAX_BODY_CHARACTERS = 200_000;

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

        [$files, $notes] = $this->files($ref->mailboxAddress, $ref->graphMessageId, $message);
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
            $made = $this->make($rfqActor, $draftId, $label, $files, $meta);
        } catch (RfqParserFailure $failure) {
            // A file the parser refuses (not a retryable outage) must not cost the whole email: the body alone is a draft.
            if ($failure->isRetryable() || count($files) < 2) {
                throw new MailUnavailable('The RFQ parser could not read the email: ' . $failure->getMessage(), MailUnavailable::DRAFTS, $failure);
            }
            $meta['attachments_not_read'] = array_merge($notes, ['the parser refused the attachments: ' . $failure->getMessage()]);
            try {
                $made = $this->make($rfqActor, $draftId, $label, [$files[0]], $meta);
            } catch (RfqParserFailure $again) {
                throw new MailUnavailable('The RFQ parser could not read the email: ' . $again->getMessage(), MailUnavailable::DRAFTS, $again);
            }
        }

        return new CreatedDraft($made, StoreProjection::reference($draftId), $title);
    }

    /**
     * @param list<array{fileName: string, contentType: string, bytes: string}> $files
     * @param array<string, mixed> $meta
     *
     * @throws RfqParserFailure
     * @throws MailUnavailable
     */
    private function make(RfqActor $actor, string $draftId, string $label, array $files, array $meta): string
    {
        try {
            return $this->rfqs->createFromEmail($actor, $this->mailboxEmail, $draftId, $label, $files, $meta, CorrelationId::generate())['publicId'];
        } catch (RfqActionForbidden $failure) {
            throw new MailUnavailable($this->mailbox . ' may not create RFQs (it needs RFQ.DRAFT.CREATE).', MailUnavailable::DRAFTS, $failure);
        } catch (InvalidRfqValue|RfqStoreUnavailable $failure) {
            throw new MailUnavailable('The RFQ draft could not be made: ' . $failure->getMessage(), MailUnavailable::DRAFTS, $failure);
        }
    }

    /**
     * The body, then the attachments the parser can read and that fit its limits.
     *
     * @return array{0: list<array{fileName: string, contentType: string, bytes: string}>, 1: list<string>} the files, and what was left out
     */
    private function files(string $mailbox, string $graphMessageId, MessageView $message): array
    {
        try {
            $body = $this->fetcher->body($mailbox, $graphMessageId);
        } catch (MailNotFound $failure) {
            throw new MailUnavailable('The message is no longer in the mailbox.', MailUnavailable::DRAFTS, $failure);
        } catch (MailFailure $failure) {
            throw new MailUnavailable($failure->getMessage(), MailUnavailable::DRAFTS, $failure);
        }
        $text = $body['type'] === 'html' ? HtmlToText::convert($body['content']) : HtmlToText::tidy($body['content']);
        $text = trim(mb_substr($text, 0, self::MAX_BODY_CHARACTERS));
        if ($text === '') {
            $text = trim($message->preview) !== '' ? trim($message->preview) : '(empty message)';
        }
        $files = [['fileName' => self::BODY_FILE, 'contentType' => 'text/plain', 'bytes' => $text]];

        $notes = [];
        foreach ($message->attachments as $attachment) {
            $extension = strtolower(pathinfo($attachment->fileName, PATHINFO_EXTENSION));
            if (!in_array($extension, self::READABLE, true)) {
                $notes[] = $attachment->fileName . ': not a format the parser reads';
                continue;
            }
            if (count($files) >= RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE) {
                $notes[] = $attachment->fileName . ': too many files on one email';
                continue;
            }
            $ref = $this->store->attachmentRef($attachment->id);
            if ($ref === null) {
                continue;
            }
            $cap = in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true) ? RfqServiceLimits::MAX_IMAGE_BYTES : RfqServiceLimits::MAX_DOCUMENT_BYTES;
            if ($ref->sizeBytes > $cap) {
                $notes[] = $attachment->fileName . ': larger than the parser accepts';
                continue;
            }
            try {
                $download = $this->fetcher->attachment($ref->mailboxAddress, $ref->graphMessageId, $ref->graphAttachmentId);
            } catch (MailNotFound) {
                $notes[] = $attachment->fileName . ': no longer on the message';
                continue;
            } catch (MailFailure $failure) {
                // Graph is failing: the whole attempt is retried, rather than a draft made without the file.
                throw new MailUnavailable($failure->getMessage(), MailUnavailable::DRAFTS, $failure);
            }
            if ($download->content === '' || strlen($download->content) > $cap) {
                $notes[] = $attachment->fileName . ': empty or larger than the parser accepts';
                continue;
            }
            $files[] = ['fileName' => $attachment->fileName, 'contentType' => strtolower(trim($download->mimeType)), 'bytes' => $download->content];
        }

        return [$files, $notes];
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
