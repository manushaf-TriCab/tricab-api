<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Notify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Notify\InMemoryRfqMailNotificationStore;
use TriCab\Api\Mail\Notify\RfqMailNotifier;
use TriCab\Api\Mail\Notify\StaticRfqMailRecipients;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Mail\Intake\Mails;
use TriCab\Api\Tests\Mail\Intake\RecordingDraftCreator;
use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\Service\MailDraftAutomation;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\RfqDraftNotifier;

final class RfqMailNotifierTest extends TestCase
{
    public function test_every_recipient_but_the_mailbox_user_gets_one_unread_notice_linking_the_draft(): void
    {
        $store = new InMemoryRfqMailNotificationStore();
        $notifier = new RfqMailNotifier(new StaticRfqMailRecipients([1, 2, 9]), $store, FixedClock::utc('2026-11-03 01:00:00'), 9);
        $draft = new CreatedDraft('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'RFQ-1', 'Email – Cable for site');

        $notifier->newEmailDraft($draft, new MailParty('Pat Buyer', 'pat@example.com'));
        $notifier->newEmailDraft($draft, new MailParty('Pat Buyer', 'pat@example.com'));

        $one = $store->forUser(1);
        self::assertCount(1, $one, 'a repeat does not double up');
        self::assertFalse($one[0]->read);
        self::assertSame('/admin/quotegen/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $one[0]->link);
        self::assertStringContainsString('Cable for site', $one[0]->title);
        self::assertStringContainsString('Pat Buyer', $one[0]->body);
        self::assertCount(1, $store->forUser(2));
        self::assertSame([], $store->forUser(9), 'the mailbox user owns the draft and is not told');

        $store->markAllRead(1);
        self::assertTrue($store->forUser(1)[0]->read);
        self::assertFalse($store->forUser(2)[0]->read);
    }

    public function test_the_automation_notifies_for_a_new_draft_only_not_a_followup_and_survives_a_failing_notifier(): void
    {
        $now = new DateTimeImmutable('2026-11-03 01:00:00 UTC');
        $mail = new InMemoryMailStore();
        $recorder = new MailIntakeRecorder($mail);
        $clock = FixedClock::utc('2026-11-03 01:00:00');
        $calls = [];
        $notifier = new class ($calls) implements RfqDraftNotifier {
            /** @param list<string> $calls */
            public function __construct(public array &$calls)
            {
            }

            public function newEmailDraft(CreatedDraft $draft, MailParty $sender): void
            {
                $this->calls[] = $draft->publicId . '|' . $sender->address;
            }
        };
        $automation = new MailDraftAutomation($mail, new RecordingDraftCreator(), Mails::MAILBOX, $clock, $notifier);
        $receive = function (string $key, string $label, string $conversation) use ($recorder, $now): array {
            $m = Mails::mail($key, conversation: $conversation);
            $r = $recorder->recordMessage(new NewMessage(Mails::MAILBOX, $m->graphMessageId, $m->internetMessageId, $m->conversationId, $m->subject, $m->from, $m->to, $m->cc, $m->receivedAt, $m->bodyPreview, false, []));
            $recorder->recordAssessment(Mails::MAILBOX, $m->internetMessageId, NewAssessment::classified($label, 0.9, 'because', 'model', 'v1', $now, 10, false));

            return ['t' => $r->threadId, 'm' => $r->messageId];
        };

        $a = $receive('a', 'rfq', 'conv-1');
        self::assertSame(MailDraftAutomation::CREATED, $automation->apply($a['t'], $a['m'], 'rfq', false));
        self::assertCount(1, $calls);
        // Redelivery and a follow-up in the same conversation tell nobody.
        $automation->apply($a['t'], $a['m'], 'rfq', false);
        $b = $receive('b', 'rfq_followup', 'conv-1');
        self::assertSame(MailDraftAutomation::FOLLOWUP, $automation->apply($b['t'], $b['m'], 'rfq_followup', false));
        self::assertCount(1, $calls);

        $throwing = new class implements RfqDraftNotifier {
            public function newEmailDraft(CreatedDraft $draft, MailParty $sender): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $second = new MailDraftAutomation($mail, new RecordingDraftCreator(), Mails::MAILBOX, $clock, $throwing);
        $c = $receive('c', 'rfq', 'conv-2');
        self::assertSame(MailDraftAutomation::CREATED, @$second->apply($c['t'], $c['m'], 'rfq', false), 'the draft stands when the notice fails');
    }
}
