<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The rules of finance mail, framework-free. Ported from the finance portal's
 * `mail_send` so an app sends under the rules it sends under today:
 *
 *  - Refused unless the app holds a send grant on the mailbox and the mailbox is
 *    active.
 *  - Recipients are lower-cased, de-duplicated and valid addresses with no
 *    display name; at least one `to`; no more than 50 per list or 50 in all.
 *  - A subject (at most 500 characters), a body of at most 1 MiB, content type
 *    HTML or TEXT, at most 10 attachments of 3 MiB together, each with a plain
 *    file name.
 *  - A `pending` ledger row is written before anything is sent, then `sent`, or
 *    `failed` with the error. The ledger keeps the body's hash and the attachment
 *    names, never the content, and no bcc address.
 *  - An idempotency key is unique per app. A key already `sent` is not sent again
 *    and returns the earlier delivery. A key that `failed` (or is still `pending`)
 *    is refused as a conflict, so the caller retries under a new key.
 *  - A transport that sends nothing because mail is switched off leaves the row
 *    `suppressed` and the send returns it; that is not a failure. A `suppressed`
 *    key is not a conflict: sending under it again reuses its row and sends.
 *  - A send may have no actor.
 *
 * Not carried: the portal's check that the acting user holds the app's mail
 * permission and a mailbox-scoped grant. Here the calling route checks the
 * permission, and mailbox scopes arrive with F1.10.
 */
final class FinanceMailService implements FinanceMailSender
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param (callable(): DateTimeImmutable)|null $now
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly FinanceMailboxRegistry $mailboxes,
        private readonly FinanceMailLedger $ledger,
        private readonly FinanceMailTransport $transport,
        ?callable $now = null,
        ?callable $newId = null,
    ) {
        $this->now = Closure::fromCallable($now ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    public function send(FinanceMailMessage $message): FinanceMailResult
    {
        $mailbox = $this->mailboxes->find(self::address($message->mailbox));
        if ($mailbox === null || !$mailbox->canSend($message->application)) {
            throw FinanceMailRefused::forMailbox($message->application, trim($message->mailbox));
        }

        $to = self::addresses($message->to, 'to');
        $cc = self::addresses($message->cc, 'cc');
        $bcc = self::addresses($message->bcc, 'bcc');
        if ($to === []) {
            throw new FinanceMailInvalid('At least one to address is required.');
        }
        if (count($to) + count($cc) + count($bcc) > FinanceMailRules::MAX_RECIPIENTS) {
            throw new FinanceMailInvalid(sprintf('A message cannot have more than %d recipients.', FinanceMailRules::MAX_RECIPIENTS));
        }

        $subject = trim($message->subject);
        if ($subject === '' || mb_strlen($subject) > FinanceMailRules::MAX_SUBJECT_LENGTH) {
            throw new FinanceMailInvalid(sprintf('The subject is required and cannot exceed %d characters.', FinanceMailRules::MAX_SUBJECT_LENGTH));
        }
        if (strlen($message->body) > FinanceMailRules::MAX_BODY_BYTES) {
            throw new FinanceMailInvalid('The message body is too large.');
        }
        $contentType = strtoupper(trim($message->contentType));
        if ($contentType !== FinanceMailRules::CONTENT_HTML && $contentType !== FinanceMailRules::CONTENT_TEXT) {
            throw new FinanceMailInvalid('The content type must be HTML or TEXT.');
        }

        $key = $message->idempotencyKey === null ? null : trim($message->idempotencyKey);
        $key = $key === '' ? null : $key;
        if ($key !== null && mb_strlen($key) > FinanceMailRules::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new FinanceMailInvalid(sprintf('The idempotency key cannot exceed %d characters.', FinanceMailRules::MAX_IDEMPOTENCY_KEY_LENGTH));
        }
        $suppressed = null;
        if ($key !== null) {
            $existing = $this->ledger->findByIdempotencyKey($message->application, $key);
            if ($existing !== null) {
                if (!$existing->isSuppressed()) {
                    return self::earlier($existing);
                }
                $suppressed = $existing;
            }
        }

        $names = self::checkAttachments($message->attachments);

        $delivery = new FinanceMailDelivery(
            $suppressed->id ?? ($this->newId)(),
            $message->application,
            $message->actorUserId,
            $mailbox->address,
            $to,
            $cc,
            $subject,
            hash('sha256', $message->body),
            $names,
            $key,
            FinanceMailRules::STATUS_PENDING,
            null,
            ($this->now)(),
            null,
        );

        $recorded = $suppressed === null ? $this->ledger->recordPending($delivery) : $this->ledger->reopenSuppressed($delivery);
        if (!$recorded) {
            // Another caller took the key between the check and the write.
            $existing = $key === null ? null : $this->ledger->findByIdempotencyKey($message->application, $key);
            if ($existing === null) {
                throw new FinanceMailInvalid('The send could not be recorded.');
            }

            return self::earlier($existing);
        }

        $threaded = false;
        try {
            $outgoing = $message->normalised($mailbox->address, $to, $cc, $bcc, $subject, $contentType, $key);
            if ($outgoing->replyToMessageId !== null && $this->transport instanceof FinanceReplyMailTransport) {
                $threaded = $this->transport->deliverReply($outgoing, $outgoing->replyToMessageId);
            } else {
                $this->transport->deliver($outgoing);
            }
        } catch (FinanceMailSuppressed $off) {
            $reason = mb_substr($off->getMessage(), 0, FinanceMailRules::MAX_ERROR_LENGTH);
            $this->ledger->markSuppressed($delivery->id, $reason);

            return new FinanceMailResult($delivery->withSuppressed($reason), false);
        } catch (Throwable $failure) {
            $error = mb_substr($failure->getMessage(), 0, FinanceMailRules::MAX_ERROR_LENGTH);
            $this->ledger->markFailed($delivery->id, $error);
            $failed = $failure instanceof FinanceMailTransportFailed ? $failure : new FinanceMailTransportFailed($error, 0, $failure);
            $failed->delivery = $delivery->withFailed($error);
            throw $failed;
        }

        $sentAt = ($this->now)();
        $this->ledger->markSent($delivery->id, $sentAt);

        return new FinanceMailResult($delivery->withSent($sentAt), false, $threaded);
    }

    private static function earlier(FinanceMailDelivery $existing): FinanceMailResult
    {
        if ($existing->isSent()) {
            return new FinanceMailResult($existing, true);
        }
        if ($existing->isSuppressed()) {
            // The caller that won the race for the key was suppressed too.
            return new FinanceMailResult($existing, false);
        }

        throw new FinanceMailConflict($existing);
    }

    private static function address(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function addresses(array $values, string $field): array
    {
        $out = [];
        foreach ($values as $value) {
            $address = self::address($value);
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new FinanceMailInvalid(sprintf('Invalid email address in %s.', $field));
            }
            if (!in_array($address, $out, true)) {
                $out[] = $address;
            }
        }
        if (count($out) > FinanceMailRules::MAX_RECIPIENTS) {
            throw new FinanceMailInvalid(sprintf('%s cannot contain more than %d addresses.', $field, FinanceMailRules::MAX_RECIPIENTS));
        }

        return $out;
    }

    /**
     * @param list<FinanceMailAttachment> $attachments
     * @return list<string> the names, for the ledger
     */
    private static function checkAttachments(array $attachments): array
    {
        if (count($attachments) > FinanceMailRules::MAX_ATTACHMENTS) {
            throw new FinanceMailInvalid(sprintf('At most %d attachments are allowed.', FinanceMailRules::MAX_ATTACHMENTS));
        }
        $names = [];
        $total = 0;
        foreach ($attachments as $attachment) {
            $name = trim($attachment->name);
            if (
                $name === ''
                || mb_strlen($name) > FinanceMailRules::MAX_ATTACHMENT_NAME_LENGTH
                || str_contains($name, '/')
                || str_contains($name, '\\')
                || preg_match('/[\x00-\x1f]/', $name) === 1
            ) {
                throw new FinanceMailInvalid('Each attachment requires a safe file name.');
            }
            $total += strlen($attachment->content);
            $names[] = $name;
        }
        if ($total > FinanceMailRules::MAX_ATTACHMENT_BYTES) {
            throw new FinanceMailInvalid('The combined attachment size exceeds the limit.');
        }

        return $names;
    }
}
