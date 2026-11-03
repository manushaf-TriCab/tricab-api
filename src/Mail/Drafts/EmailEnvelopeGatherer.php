<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Drafts;

use TriCab\Core\Mail\Application\DTO\MessageView;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Domain\Exception\MailFailure;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Intake\HtmlToText;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * Builds the `rfq-mail-envelope.v1` the parser reads from what Exchange holds now (GETs only). Shared by the draft
 * creator (M6.1) and the follow-up proposer (M6.2), so a follow-up is read exactly as the first email was.
 */
final class EmailEnvelopeGatherer
{
    /** What the parser reads (`rfq_parser/intake.py`); anything else on an email is left on the email. */
    private const READABLE = ['xlsx', 'xlsm', 'xls', 'docx', 'pdf', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'webp'];

    /** The attachments that are pictures; an inline one is a pasted schedule or decoration. */
    private const IMAGES = ['png', 'jpg', 'jpeg', 'webp'];

    /** The parser's floor for an inline image (`RFQ_MIN_INLINE_IMAGE_BYTES`): smaller is a logo, so it is not downloaded. */
    private const MIN_INLINE_IMAGE_BYTES = 25_600;

    private const BODY_FILE = 'email-body.txt';

    private const MAX_BODY_CHARACTERS = 200_000;

    public function __construct(private readonly MailBodyFetcher $fetcher)
    {
    }

    /**
     * The email as the parser reads it (`rfq-mail-envelope.v1`, built from what Exchange holds now), and the files
     * the draft keeps: the body as text first, then every attachment sent in the envelope, in order, so that
     * `email-N` is the same file in both.
     *
     * The envelope carries the body untouched (the parser flattens the HTML, keeps its tables and splits the
     * forwarded chain with the old application's code), both recipient lists and the file attachments the parser can
     * read and that fit its limits, inline images included. Decoration (a logo under 25 KB, a `.gif`) is not
     * downloaded. Only GETs go to Exchange.
     *
     * @return array{0: list<array{fileName: string, contentType: string, bytes: string}>, 1: list<string>, 2: array<string, mixed>} the kept files, what was left out, the envelope
     */
    public function gather(string $mailbox, string $graphMessageId, MessageView $message, string $subject): array
    {
        try {
            $source = $this->fetcher->envelopeSource($mailbox, $graphMessageId);
        } catch (MailNotFound $failure) {
            throw new MailUnavailable('The message is no longer in the mailbox.', MailUnavailable::DRAFTS, $failure);
        } catch (MailFailure $failure) {
            throw new MailUnavailable($failure->getMessage(), MailUnavailable::DRAFTS, $failure);
        }
        $text = $source['type'] === 'html' ? HtmlToText::convert($source['content']) : HtmlToText::tidy($source['content']);
        $text = trim(mb_substr($text, 0, self::MAX_BODY_CHARACTERS));
        if ($text === '') {
            $text = trim($message->preview) !== '' ? trim($message->preview) : '(empty message)';
        }
        $files = [['fileName' => self::BODY_FILE, 'contentType' => 'text/plain', 'bytes' => $text]];

        // Files before inline images, so that a crowded message loses a pasted picture before it loses a schedule.
        $ordered = [];
        foreach ([false, true] as $inline) {
            foreach ($source['attachments'] as $attachment) {
                if ($attachment->isInline === $inline) {
                    $ordered[] = $attachment;
                }
            }
        }

        $notes = [];
        $documents = [];
        $total = 0;
        foreach ($ordered as $attachment) {
            $extension = strtolower(pathinfo($attachment->name, PATHINFO_EXTENSION));
            $image = in_array($extension, self::IMAGES, true);
            if ($attachment->isInline && (!$image || $attachment->size < self::MIN_INLINE_IMAGE_BYTES)) {
                continue;
            }
            if (!in_array($extension, self::READABLE, true)) {
                $notes[] = $attachment->name . ': not a format the parser reads';
                continue;
            }
            if (count($documents) >= RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE) {
                $notes[] = $attachment->name . ': too many files on one email';
                continue;
            }
            $cap = $image ? RfqServiceLimits::MAX_IMAGE_BYTES : RfqServiceLimits::MAX_DOCUMENT_BYTES;
            if ($attachment->size > $cap) {
                $notes[] = $attachment->name . ': larger than the parser accepts';
                continue;
            }
            if ($total + $attachment->size > ParseRequest::MAX_EMAIL_ATTACHMENT_BYTES) {
                $notes[] = $attachment->name . ': the email\'s attachments are over the size one parse takes';
                continue;
            }
            try {
                $download = $this->fetcher->attachment($mailbox, $graphMessageId, $attachment->id);
            } catch (MailNotFound) {
                $notes[] = $attachment->name . ': no longer on the message';
                continue;
            } catch (MailFailure $failure) {
                // Graph is failing: the whole attempt is retried, rather than a draft made without the file.
                throw new MailUnavailable($failure->getMessage(), MailUnavailable::DRAFTS, $failure);
            }
            $size = strlen($download->content);
            if ($size === 0 || $size > $cap || $total + $size > ParseRequest::MAX_EMAIL_ATTACHMENT_BYTES) {
                $notes[] = $attachment->name . ': empty or larger than the parser accepts';
                continue;
            }
            $total += $size;
            $type = strtolower(trim($download->mimeType));
            $files[] = ['fileName' => $attachment->name, 'contentType' => $type, 'bytes' => $download->content];
            $documents[] = [
                'documentPublicId' => 'email-' . count($files),
                'fileName' => $attachment->name,
                'contentType' => $type,
                'bytes' => $download->content,
                'isInline' => $attachment->isInline,
            ];
        }

        $envelope = [
            'internetMessageId' => $message->internetMessageId,
            'messageId' => $graphMessageId,
            'receivedAt' => $message->receivedAt->format('c'),
            'mailbox' => $mailbox,
            'subject' => $subject,
            'sender' => ['name' => $message->from->name, 'address' => $message->from->address],
            'toRecipients' => array_map(static fn (MailboxRecipient $r): array => ['name' => $r->name, 'address' => $r->address], $source['to']),
            'ccRecipients' => array_map(static fn (MailboxRecipient $r): array => ['name' => $r->name, 'address' => $r->address], $source['cc']),
            'body' => ['contentType' => $source['type'], 'content' => $source['content']],
            'documents' => $documents,
        ];

        return [$files, $notes, $envelope];
    }
}
