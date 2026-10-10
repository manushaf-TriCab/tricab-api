<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use Throwable;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * What a scanned email carries, read live from the mailbox (T6.6, `emails_body`, `emails_attachments` and
 * `emails_attachment_file`; question Q18). Nothing is stored: the scan keeps only the preview, and every look fetches the
 * message again through the shared finance reader, which needs the app's read grant on the mailbox, applies the person's
 * mailbox scope and writes one audit row per message, listing and attachment read.
 *
 * All the content is **untrusted**: anyone can mail the shared address. It is returned as it came, and the page shows it in
 * a sandboxed frame. A binned email is still readable.
 *
 * Errors: a missing or out-of-scope email is `Not found` (404); a missing read grant is a typed 403; any failure of the
 * mailbox is a 502 whose message is `Could not fetch the <thing> from Outlook: <reason>` (open question A4: the source's 502
 * for every Graph failure, a message gone from the mailbox included).
 */
final class ApMessageService
{
    /** What a browser may show in place; anything else is always a download (an HTML or SVG attachment is never shown). */
    public const INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];

    public const NO_CONTENT = 'This attachment has no file content';

    public function __construct(
        private readonly ApEmailService $emails,
        private readonly FinanceMailboxReader $mailbox,
    ) {
    }

    /**
     * `GET /emails/{id}/body`: `subject`, `from`, `to`, `cc`, `received_at`, `content_type` (`html` or `text`), `content` and
     * `untrusted: true`. An empty body reads as the stored preview, as text.
     *
     * @return array<string, mixed>
     */
    public function body(ApActor $actor, string $id): array
    {
        $email = $this->emails->get($actor, $id);
        try {
            $message = $this->mailbox->getMessage(Module::APP_AP, $email->mailbox, $email->graphMessageId, $actor->userId);
        } catch (FinanceMailRefused $refused) {
            throw new ApForbidden($refused->getMessage(), [], $refused);
        } catch (MailboxReadFailed | FinanceMailException $failure) {
            throw self::upstream('message', $failure);
        }

        return self::shape($message, $email);
    }

    /**
     * `GET /emails/{id}/attachments`: the files to show, `{id, name, content_type, size}`, in the mailbox's order. An inline
     * part is left out unless it is a PDF (signature logos and tracking images are inline; Apple Mail marks real PDFs so).
     *
     * @return list<array{id: string, name: string, content_type: string, size: int}>
     */
    public function attachments(ApActor $actor, string $id): array
    {
        $email = $this->emails->get($actor, $id);
        try {
            $items = $this->mailbox->listAttachments(Module::APP_AP, $email->mailbox, $email->graphMessageId, $actor->userId);
        } catch (FinanceMailRefused $refused) {
            throw new ApForbidden($refused->getMessage(), [], $refused);
        } catch (MailboxReadFailed | FinanceMailException $failure) {
            throw self::upstream('attachments', $failure);
        }

        $rows = [];
        foreach ($items as $item) {
            if ($item->id === '' || ($item->isInline && !str_starts_with(strtolower($item->contentType), 'application/pdf'))) {
                continue;
            }
            $rows[] = [
                'id' => $item->id,
                'name' => $item->name !== '' ? $item->name : 'attachment',
                'content_type' => $item->contentType !== '' ? $item->contentType : 'application/octet-stream',
                'size' => $item->size,
            ];
        }

        return $rows;
    }

    /**
     * `GET /emails/{id}/attachments/{aid}/file`: one attachment's bytes. `This attachment has no file content` (404) when
     * the file is empty. The caller decides inline or download from {@see ApMessageFile::$inlineAllowed} and `?inline=1`.
     */
    public function file(ApActor $actor, string $id, string $attachmentId): ApMessageFile
    {
        $email = $this->emails->get($actor, $id);
        try {
            $attachment = $this->mailbox->getAttachment(Module::APP_AP, $email->mailbox, $email->graphMessageId, $attachmentId, $actor->userId);
        } catch (FinanceMailRefused $refused) {
            throw new ApForbidden($refused->getMessage(), [], $refused);
        } catch (MailboxReadFailed | FinanceMailException $failure) {
            throw self::upstream('attachment', $failure);
        }
        if ($attachment->content === '') {
            throw new ApNotFound(self::NO_CONTENT);
        }
        $type = strtolower(trim(explode(';', $attachment->summary->contentType)[0]));
        $type = $type !== '' ? $type : 'application/octet-stream';

        return new ApMessageFile(
            $attachment->content,
            $type,
            ApAttachmentService::headerName($attachment->summary->name, 'attachment'),
            in_array($type, self::INLINE_TYPES, true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape(MailboxMessage $message, ApEmail $stored): array
    {
        $summary = $message->summary;
        $people = static fn (array $list): array => array_map(
            static fn (MailboxRecipient $r): array => ['name' => $r->name, 'address' => $r->address],
            $list,
        );
        $hasBody = $message->body !== '';

        return [
            'subject' => $summary->subject !== '' ? $summary->subject : $stored->subject,
            'from' => ['name' => $summary->fromName, 'address' => $summary->fromAddress],
            'to' => $people($message->to),
            'cc' => $people($message->cc),
            'received_at' => ApPresenter::iso($summary->receivedAt),
            'content_type' => $hasBody ? $message->bodyType : MailboxMessage::BODY_TEXT,
            'content' => $hasBody ? $message->body : $stored->bodyPreview,
            'untrusted' => true,
        ];
    }

    private static function upstream(string $what, Throwable $failure): ApUpstream
    {
        return new ApUpstream('Could not fetch the ' . $what . ' from Outlook: ' . $failure->getMessage(), ApUpstream::MAILBOX, $failure);
    }
}
