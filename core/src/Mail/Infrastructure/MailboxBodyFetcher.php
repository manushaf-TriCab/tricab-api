<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Infrastructure;

use TriCab\Core\Mail\Application\DTO\AttachmentDownload;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Shared\Mailbox\MailboxAccessDenied;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * {@see MailBodyFetcher} over the platform's {@see MailboxReader}. While the RFQ mail Graph registration
 * (RFQ M0) is not configured the reader is an {@see UnconfiguredMailboxReader}, which refuses every read
 * with {@see MailboxReadUnavailable}; that, and a Graph that cannot be reached, become a
 * {@see MailUnavailable} the route shows as a 503.
 */
final class MailboxBodyFetcher implements MailBodyFetcher
{
    public function __construct(private readonly MailboxReader $reader)
    {
    }

    public function body(string $mailboxAddress, string $graphMessageId): array
    {
        $message = $this->guarded(fn (MailboxReader $reader): MailboxMessage => $reader->getMessage($mailboxAddress, $graphMessageId));

        return ['type' => $message->bodyType, 'content' => $message->body];
    }

    public function envelopeSource(string $mailboxAddress, string $graphMessageId): array
    {
        $message = $this->guarded(fn (MailboxReader $reader): MailboxMessage => $reader->getMessage($mailboxAddress, $graphMessageId));
        $attachments = $this->guarded(fn (MailboxReader $reader): array => $reader->listAttachments($mailboxAddress, $graphMessageId));

        return ['type' => $message->bodyType, 'content' => $message->body, 'to' => $message->to, 'cc' => $message->cc, 'attachments' => $attachments];
    }

    public function attachment(string $mailboxAddress, string $graphMessageId, string $graphAttachmentId): AttachmentDownload
    {
        $file = $this->guarded(fn (MailboxReader $reader) => $reader->getAttachment($mailboxAddress, $graphMessageId, $graphAttachmentId));

        return new AttachmentDownload($file->summary->name, $file->summary->contentType, $file->content);
    }

    /**
     * @template T
     * @param callable(MailboxReader): T $read
     * @return T
     */
    private function guarded(callable $read): mixed
    {
        try {
            return $read($this->reader);
        } catch (MailboxItemNotFound $failure) {
            throw new MailNotFound('That message is no longer in the mailbox.');
        } catch (MailboxAccessDenied $failure) {
            throw new MailUnavailable('The mailbox refused the read. The RFQ mail Graph registration may not cover this mailbox.', MailUnavailable::BODY, $failure);
        } catch (MailboxReadUnavailable $failure) {
            // The reader's own words: safe to show, and never a secret.
            throw new MailUnavailable($failure->getMessage(), MailUnavailable::BODY, $failure);
        } catch (MailboxReadFailed $failure) {
            throw new MailUnavailable('The message could not be read from Exchange just now. Try again in a moment.', MailUnavailable::BODY, $failure);
        }
    }
}
