<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Contract;

use TriCab\Core\Mail\Application\DTO\AttachmentDownload;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;

/**
 * Fetches a message's body and attachments from Exchange when someone opens them (decision 0.1:
 * nothing is stored). Everything it returns is untrusted.
 */
interface MailBodyFetcher
{
    /**
     * @return array{type: string, content: string} `type` is `html` or `text`
     *
     * @throws MailUnavailable when Graph is not configured or cannot be reached
     * @throws MailNotFound    when the message is no longer in the mailbox
     */
    public function body(string $mailboxAddress, string $graphMessageId): array;

    /**
     * @throws MailUnavailable when Graph is not configured or cannot be reached
     * @throws MailNotFound    when the attachment is no longer there
     */
    public function attachment(string $mailboxAddress, string $graphMessageId, string $graphAttachmentId): AttachmentDownload;
}
