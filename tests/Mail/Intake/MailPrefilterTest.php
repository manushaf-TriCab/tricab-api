<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Intake\MailPrefilter;
use TriCab\Core\Mail\Intake\PrefilterSkip;

/** One test per skip reason (RFQ plan 3.4), and the mail that must NOT be skipped. */
final class MailPrefilterTest extends TestCase
{
    private function filter(): MailPrefilter
    {
        return new MailPrefilter(Mails::MAILBOX, ['rfq-bot@tricab.com']);
    }

    private function code(\TriCab\Core\Mail\Intake\IncomingMail $mail): ?string
    {
        return $this->filter()->check($mail)?->code;
    }

    public function test_an_ordinary_rfq_passes(): void
    {
        self::assertNull($this->code(Mails::mail('a')));
    }

    public function test_mail_from_the_mailbox_itself_is_skipped(): void
    {
        self::assertSame(PrefilterSkip::SELF_SENT, $this->code(Mails::mail('a', from: 'Melbourne@TriCab.com')));
    }

    public function test_mail_from_a_configured_own_address_is_skipped(): void
    {
        self::assertSame(PrefilterSkip::SELF_SENT, $this->code(Mails::mail('a', from: 'rfq-bot@tricab.com')));
    }

    public function test_our_reply_tag_is_skipped_even_behind_a_re_prefix(): void
    {
        self::assertSame(PrefilterSkip::OWN_REPLY, $this->code(Mails::mail('a', subject: 'RE: [RFQ-Reply] 14033 Magnum', from: 'someone@external.example')));
    }

    public function test_the_notification_tag_counts_only_from_our_own_domain(): void
    {
        self::assertSame(PrefilterSkip::OWN_NOTIFICATION, $this->code(Mails::mail('a', subject: '[RFQ] assigned to you', from: 'sales@tricab.com')));
        // A customer who writes [RFQ] in a subject still gets through to the classifier.
        self::assertNull($this->code(Mails::mail('b', subject: '[RFQ] 500m 4C 16mm', from: 'buyer@customer.example')));
    }

    public function test_a_bounce_is_skipped_by_sender_and_by_subject(): void
    {
        self::assertSame(PrefilterSkip::BOUNCE, $this->code(Mails::mail('a', from: 'MAILER-DAEMON@mail.example')));
        self::assertSame(PrefilterSkip::BOUNCE, $this->code(Mails::mail('b', subject: 'Undeliverable: Quote for cable', from: 'postmaster@x.example')));
        self::assertSame(PrefilterSkip::BOUNCE, $this->code(Mails::mail('c', subject: 'Delivery Status Notification (Failure)', from: 'noreply@x.example')));
    }

    public function test_an_auto_reply_is_skipped_by_header_and_by_subject(): void
    {
        self::assertSame(PrefilterSkip::AUTO_REPLY, $this->code(Mails::mail('a', headers: ['auto-submitted' => 'auto-replied'])));
        self::assertSame(PrefilterSkip::AUTO_REPLY, $this->code(Mails::mail('b', headers: ['x-auto-response-suppress' => 'DR, RN, NRN, OOF, AutoReply'])));
        self::assertSame(PrefilterSkip::AUTO_REPLY, $this->code(Mails::mail('c', subject: 'Automatic reply: Quote for cable')));
    }

    public function test_auto_generated_mail_still_reaches_the_classifier(): void
    {
        // ERP and procurement systems send real RFQs as auto-generated mail.
        self::assertNull($this->code(Mails::mail('a', headers: ['auto-submitted' => 'auto-generated'])));
        self::assertNull($this->code(Mails::mail('b', headers: ['auto-submitted' => 'no'])));
    }

    public function test_bulk_mail_is_skipped_by_unsubscribe_and_by_precedence(): void
    {
        self::assertSame(PrefilterSkip::BULK, $this->code(Mails::mail('a', headers: ['list-unsubscribe' => '<mailto:u@x.example>'])));
        foreach (['bulk', 'list', 'junk'] as $value) {
            self::assertSame(PrefilterSkip::BULK, $this->code(Mails::mail('p' . $value, headers: ['precedence' => $value])), $value);
        }
        self::assertNull($this->code(Mails::mail('z', headers: ['precedence' => 'first-class'])));
    }

    public function test_calendar_invites_are_skipped_by_message_type_and_by_ics(): void
    {
        self::assertSame(PrefilterSkip::CALENDAR, $this->code(Mails::mail('a', type: '#microsoft.graph.eventMessageRequest')));
        $ics = Mails::mail('b', hasAttachments: true)->withAttachments([new NewAttachment('att', 'invite.ics', 'application/octet-stream', 900)]);
        self::assertSame(PrefilterSkip::CALENDAR, $this->code($ics));
        $calendar = Mails::mail('c', hasAttachments: true)->withAttachments([new NewAttachment('att', 'x', 'text/calendar', 900)]);
        self::assertSame(PrefilterSkip::CALENDAR, $this->code($calendar));
    }

    public function test_every_skip_has_a_reason_a_person_can_read(): void
    {
        $skip = $this->filter()->check(Mails::mail('a', from: Mails::MAILBOX));
        self::assertNotNull($skip);
        self::assertStringContainsString('Our own mail', $skip->reason);
    }
}
