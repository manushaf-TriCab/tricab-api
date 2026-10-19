<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;

/**
 * What the RFQ reader (M3) and classifier (M4) call. Store behaviour (dedupe, threading, history) is in
 * {@see MailStoreBehaviour}; this is the recorder's own rules.
 */
final class MailIntakeRecorderTest extends TestCase
{
    private InMemoryMailStore $store;

    private MailIntakeRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = new InMemoryMailStore();
        $this->recorder = new MailIntakeRecorder($this->store);
    }

    public function test_it_creates_the_mailbox_on_first_sight_and_normalises_the_address(): void
    {
        $message = MailFixtures::message('a');
        $this->recorder->recordMessage(new NewMessage('  Melbourne@TriCab.com ', $message->graphMessageId, $message->internetMessageId, $message->conversationId, $message->subject, $message->from, $message->to, $message->cc, $message->receivedAt, $message->bodyPreview, false, [], 'TriCab Melbourne'));

        self::assertSame('melbourne@tricab.com', $this->store->mailboxes()[0]->address);
        self::assertSame('TriCab Melbourne', $this->store->mailboxes()[0]->displayName);
        // A later call for the same mailbox finds the same row.
        $this->recorder->recordMessage(MailFixtures::message('b'));
        self::assertCount(1, $this->store->mailboxes());
    }

    public function test_it_requires_the_ids_the_dedupe_and_the_body_fetch_depend_on(): void
    {
        $m = MailFixtures::message('a');
        foreach ([['', $m->internetMessageId], [$m->graphMessageId, ' ']] as [$graph, $internet]) {
            try {
                $this->recorder->recordMessage(new NewMessage($m->mailboxAddress, $graph, $internet, 'c', 's', $m->from, [], [], $m->receivedAt, '', false));
                self::fail('expected a refusal');
            } catch (MailInputInvalid) {
                self::assertSame([], $this->store->mailboxes(), 'nothing was written');
            }
        }
    }

    public function test_it_clips_text_to_what_the_columns_hold(): void
    {
        $m = MailFixtures::message('a');
        $long = str_repeat('é', 800);
        $recorded = $this->recorder->recordMessage(new NewMessage($m->mailboxAddress, $m->graphMessageId, $m->internetMessageId, $m->conversationId, $long, new MailParty($long, 'A@B.EXAMPLE'), [], [], $m->receivedAt, $long . $long, false, [new NewAttachment('x', $long, 'application/pdf', -5)]));
        $this->recorder->recordAssessment($m->mailboxAddress, $m->internetMessageId, NewAssessment::classified('rfq', 1.0, $long . $long, 'm', 'v', $m->receivedAt));

        $record = $this->store->thread($recorded->threadId);
        self::assertNotNull($record);
        self::assertSame(1000, mb_strlen($record->summary->preview));
        self::assertSame(200, mb_strlen($record->summary->from->name));
        self::assertSame('a@b.example', $record->summary->from->address);
        self::assertSame(1000, mb_strlen($record->assessment->reason));
        self::assertSame(500, mb_strlen($record->messages[0]->attachments[0]->fileName));
        self::assertSame(0, $record->messages[0]->attachments[0]->sizeBytes);
    }

    public function test_a_message_without_a_conversation_id_gets_a_thread_of_its_own(): void
    {
        $a = $this->recorder->recordMessage(MailFixtures::message('a'));
        $b = $this->recorder->recordMessage(new NewMessage(
            'melbourne@tricab.com', 'graph-b', '<b@x.example>', '', 'No conversation', new MailParty('B', 'b@x.example'), [], [], MailFixtures::at(), '', false,
        ));
        $c = $this->recorder->recordMessage(new NewMessage(
            'melbourne@tricab.com', 'graph-c', '<c@x.example>', '', 'No conversation either', new MailParty('C', 'c@x.example'), [], [], MailFixtures::at(), '', false,
        ));

        self::assertNotSame($a->threadId, $b->threadId);
        self::assertNotSame($b->threadId, $c->threadId);
    }

    public function test_assessing_checks_the_message_exists_and_the_values_make_sense(): void
    {
        try {
            $this->recorder->recordAssessment('melbourne@tricab.com', '<ghost@x.example>', NewAssessment::classified('rfq', 0.5, '', 'm', 'v', MailFixtures::at()));
            self::fail('expected a refusal');
        } catch (MailNotFound) {
            $this->addToAssertionCount(1);
        }
        foreach ([
            static fn () => NewAssessment::classified('maybe', 0.5, '', 'm', 'v', MailFixtures::at()),
            static fn () => NewAssessment::classified('rfq', 1.2, '', 'm', 'v', MailFixtures::at()),
            static fn () => NewAssessment::classified('rfq', -0.1, '', 'm', 'v', MailFixtures::at()),
            static fn () => NewAssessment::classified('rfq', 0.5, '', 'm', 'v', MailFixtures::at(), -3),
            static fn () => NewAssessment::skipped('  ', 'v', MailFixtures::at()),
        ] as $make) {
            try {
                $make();
                self::fail('expected a refusal');
            } catch (MailInputInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_skip_is_a_not_rfq_with_full_confidence_and_its_reason_is_the_summary(): void
    {
        $skip = NewAssessment::skipped('Bulk mail (List-Unsubscribe)', 'v', MailFixtures::at());

        self::assertTrue($skip->skipped);
        self::assertSame(['not_rfq', 1.0, 'Bulk mail (List-Unsubscribe)', 'Bulk mail (List-Unsubscribe)'], [$skip->label, $skip->confidence, $skip->reason, $skip->skipReason]);
    }

    public function test_recording_never_touches_review_state_or_decisions(): void
    {
        $m = MailFixtures::classified($this->recorder, 'r1', 'uncertain', 0.4, conversation: 'conv-r');
        $this->store->recordIgnore($m->threadId, new MailParty('Sam', 'sam@tricab.com'), 7, null, MailFixtures::at('2026-10-18 10:00:00'));
        $this->recorder->recordMessage(MailFixtures::message('r2', 'conv-r', 'Re: x', '2026-10-18 11:00:00'));

        self::assertSame('ignored', $this->store->thread($m->threadId)?->summary->reviewState);
    }

    public function test_the_sync_heartbeat_validates_the_connection(): void
    {
        $this->recorder->recordSync('melbourne@tricab.com', 'paused', MailFixtures::at());
        self::assertSame('paused', $this->store->mailboxes()[0]->connection);

        $this->expectException(MailInputInvalid::class);
        $this->recorder->recordSync('melbourne@tricab.com', 'asleep', MailFixtures::at());
    }
}
