<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use TriCab\Core\Mail\Application\DTO\NewAttachment;

/**
 * The checks that need no model (RFQ plan 3.4): our own mail and loop guards, bounces, auto-replies,
 * bulk mail and calendar invites. The first match wins, in the order of the codes below. Anything that
 * matches none goes to the classifier.
 *
 * "Our own mail" is ported from the old app's `is_own_mail` (mail_ingest.py): the mailbox is the sender
 * of everything it sends, so the sender is the catch-all; the `[RFQ-Reply]` tag is stamped into the
 * subject of the replies the old worker sent. Two differences, both to protect real RFQs. The old
 * `[RFQ]` notification tag is honoured only from our own domain (a customer may write "[RFQ]" in a
 * subject). `Auto-Submitted: auto-generated` is not skipped: ERP and procurement systems send real
 * RFQs that way, and a missed RFQ costs more than a model call. `auto-replied` and `auto-notified` are.
 */
final class MailPrefilter
{
    public const REPLY_TAG = '[rfq-reply]';
    public const NOTIFICATION_TAG = '[rfq]';

    /** @var list<string> */
    private const BOUNCE_SUBJECTS = [
        'undeliverable:', 'undelivered mail returned', 'delivery status notification', 'mail delivery failed',
        'mail delivery subsystem', 'returned mail', 'delivery has failed', 'failure notice', 'delivery failure',
        'non-delivery report',
    ];

    /** @var list<string> */
    private const AUTO_REPLY_SUBJECTS = [
        'automatic reply:', 'auto reply:', 'autoreply:', 'auto-reply:', 'out of office', 'out-of-office', 'automatische antwort',
    ];

    /** @param list<string> $ownAddresses lower-case addresses that are ours besides the mailbox */
    public function __construct(private readonly string $mailbox, private readonly array $ownAddresses = [])
    {
    }

    public function check(IncomingMail $mail): ?PrefilterSkip
    {
        $sender = strtolower(trim($mail->from->address));
        $subject = strtolower(trim($mail->subject));
        $headers = $mail->headers ?? [];

        $own = array_merge([strtolower(trim($this->mailbox))], $this->ownAddresses);
        if ($sender !== '' && in_array($sender, $own, true)) {
            return new PrefilterSkip(PrefilterSkip::SELF_SENT, 'Our own mail: sent from ' . $sender);
        }
        if (str_contains($subject, self::REPLY_TAG)) {
            return new PrefilterSkip(PrefilterSkip::OWN_REPLY, 'Our own mail: an RFQ-Reply message');
        }
        if (str_contains($subject, self::NOTIFICATION_TAG) && $sender !== '' && self::domain($sender) === self::domain($this->mailbox)) {
            return new PrefilterSkip(PrefilterSkip::OWN_NOTIFICATION, 'Our own mail: an [RFQ] notification from our domain');
        }

        $local = strstr($sender, '@', true);
        if ($local !== false && in_array($local, ['mailer-daemon', 'postmaster'], true)) {
            return new PrefilterSkip(PrefilterSkip::BOUNCE, 'Bounce: sent by ' . $local);
        }
        foreach (self::BOUNCE_SUBJECTS as $needle) {
            if (str_contains($subject, $needle)) {
                return new PrefilterSkip(PrefilterSkip::BOUNCE, 'Bounce: delivery failure notice');
            }
        }

        $autoSubmitted = strtolower($headers['auto-submitted'] ?? '');
        if (str_starts_with($autoSubmitted, 'auto-replied') || str_starts_with($autoSubmitted, 'auto-notified')) {
            return new PrefilterSkip(PrefilterSkip::AUTO_REPLY, 'Auto-reply: Auto-Submitted ' . $autoSubmitted);
        }
        $suppress = strtolower($headers['x-auto-response-suppress'] ?? '');
        if (preg_match('/\b(oof|autoreply)\b/', $suppress) === 1) {
            return new PrefilterSkip(PrefilterSkip::AUTO_REPLY, 'Auto-reply: X-Auto-Response-Suppress marks it automatic');
        }
        foreach (self::AUTO_REPLY_SUBJECTS as $needle) {
            if (str_starts_with($subject, $needle) || str_starts_with($subject, 're: ' . $needle)) {
                return new PrefilterSkip(PrefilterSkip::AUTO_REPLY, 'Auto-reply: automatic reply subject');
            }
        }

        if (str_starts_with(strtolower($mail->odataType), '#microsoft.graph.eventmessage')) {
            return new PrefilterSkip(PrefilterSkip::CALENDAR, 'Calendar invite: a meeting message');
        }
        foreach ($mail->attachments as $attachment) {
            if (self::isCalendarFile($attachment)) {
                return new PrefilterSkip(PrefilterSkip::CALENDAR, 'Calendar invite: an .ics attachment');
            }
        }
        if (str_starts_with(strtolower($headers['content-type'] ?? ''), 'text/calendar')) {
            return new PrefilterSkip(PrefilterSkip::CALENDAR, 'Calendar invite: a calendar message');
        }

        if (isset($headers['list-unsubscribe'])) {
            return new PrefilterSkip(PrefilterSkip::BULK, 'Bulk mail: has a List-Unsubscribe header');
        }
        $precedence = strtolower($headers['precedence'] ?? '');
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return new PrefilterSkip(PrefilterSkip::BULK, 'Bulk mail: Precedence ' . $precedence);
        }

        return null;
    }

    private static function isCalendarFile(NewAttachment $a): bool
    {
        return str_starts_with(strtolower($a->mimeType), 'text/calendar')
            || str_starts_with(strtolower($a->mimeType), 'application/ics')
            || str_ends_with(strtolower($a->fileName), '.ics');
    }

    private static function domain(string $address): string
    {
        $at = strrchr($address, '@');

        return $at === false ? '' : strtolower(substr($at, 1));
    }
}
