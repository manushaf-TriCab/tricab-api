<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

use DateTimeImmutable;

/**
 * Reads a shared mailbox: the messages received in a window, one message with its
 * body, and its attachments. Nothing here sends, moves, deletes or marks anything.
 *
 * It lives outside the finance namespace so that RFQ's mail intake can use it too
 * (plan 5.1, 6). It knows nothing of grants or who is asking: a module that wants a
 * mailbox read puts its own rules in front (finance does, in
 * `FinanceMailboxReadService`).
 *
 * **Message and attachment ids are Microsoft Graph immutable ids** and nothing else:
 * the default id changes whenever a message is filed into another folder, and an
 * id stored from it stops working (Tri-AP learnt this on 2 Oct 2026). Callers may
 * keep what this returns.
 *
 * Throws {@see MailboxItemNotFound}, {@see MailboxAccessDenied} or
 * {@see MailboxReadUnavailable}; never returns "nothing" for "could not read".
 */
interface MailboxReader
{
    /**
     * Messages received in [$from, $to], newest first, at most $maxMessages. Not
     * only the Inbox: every folder, as the apps scan today.
     *
     * @return list<MailboxMessageSummary>
     */
    public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array;

    /** One message with its body and recipients. */
    public function getMessage(string $mailbox, string $messageId): MailboxMessage;

    /**
     * The message's attachments without their content (names, types, sizes), the right
     * call for showing a list.
     *
     * @return list<MailboxAttachmentSummary>
     */
    public function listAttachments(string $mailbox, string $messageId): array;

    /** One attachment with its bytes. Only file attachments have any. */
    public function getAttachment(string $mailbox, string $messageId, string $attachmentId): MailboxAttachment;
}
