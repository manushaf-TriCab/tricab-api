<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use TriCab\Core\Mail\Application\DTO\AttachmentDownload;
use TriCab\Core\Mail\Application\DTO\MessageBody;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailTooLarge;
use TriCab\Core\Mail\Domain\Service\MailHtmlCleaner;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;

/**
 * A message's body and its attachments, fetched from Exchange on open and kept nowhere (decision 0.1).
 * The body is cleaned here before it leaves the server; the browser sanitises it again.
 *
 * Attachments are capped at what the RFQ parser itself accepts, so anything the inbox lets someone
 * open is also something the parser could read: 8 MB for an image, 20 MB for any other file.
 */
final class MailMessageService
{
    public function __construct(
        private readonly MailStore $store,
        private readonly MailBodyFetcher $fetcher,
        private readonly MailHtmlCleaner $cleaner = new MailHtmlCleaner(),
    ) {
    }

    public function body(string $messageId): MessageBody
    {
        $ref = $this->store->messageRef($messageId);
        if ($ref === null) {
            throw new MailNotFound('That message was not found.');
        }
        $fetched = $this->fetcher->body($ref->mailboxAddress, $ref->graphMessageId);
        $cleaned = $fetched['type'] === 'html'
            ? $this->cleaner->cleanHtml($fetched['content'])
            : $this->cleaner->fromText($fetched['content']);

        return new MessageBody($messageId, $cleaned->html, $cleaned->remoteImagesBlocked, $cleaned->quotedHtml);
    }

    public function attachment(string $attachmentId): AttachmentDownload
    {
        $ref = $this->store->attachmentRef($attachmentId);
        if ($ref === null) {
            throw new MailNotFound('That attachment was not found.');
        }
        $cap = self::cap($ref->mimeType);
        if ($ref->sizeBytes > $cap) {
            throw self::tooLarge($ref->fileName, $cap);
        }
        $download = $this->fetcher->attachment($ref->mailboxAddress, $ref->graphMessageId, $ref->graphAttachmentId);
        // Graph's size is the sender's claim until the bytes are in hand.
        if (strlen($download->content) > $cap) {
            throw self::tooLarge($ref->fileName, $cap);
        }

        return $download;
    }

    public static function cap(string $mimeType): int
    {
        return str_starts_with(strtolower($mimeType), 'image/') ? RfqServiceLimits::MAX_IMAGE_BYTES : RfqServiceLimits::MAX_DOCUMENT_BYTES;
    }

    private static function tooLarge(string $name, int $cap): MailTooLarge
    {
        return new MailTooLarge(sprintf('"%s" is over %d MB, the largest file the inbox opens.', $name, intdiv($cap, 1024 * 1024)));
    }
}
