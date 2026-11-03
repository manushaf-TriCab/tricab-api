<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Tests\Mail\Intake\Mails;
use TriCab\Api\Tests\Mail\Intake\RecordingDraftCreator;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\ThreadRecord;
use TriCab\Core\Mail\Application\Service\MailDraftAutomation;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Shared\Time\Clock;

final class MailDraftAutomationTest extends TestCase
{
    private InMemoryMailStore $store;

    private RecordingDraftCreator $creator;

    private MailDraftAutomation $automation;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-03 01:00:00 UTC');
        $this->store = new InMemoryMailStore();
        $this->creator = new RecordingDraftCreator();
        $now = $this->now;
        $clock = new class ($now) implements Clock {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $this->automation = new MailDraftAutomation($this->store, $this->creator, Mails::MAILBOX, $clock);
    }

    /** @return array{threadId: string, messageId: string} */
    private function receive(string $key, string $label, string $conversation = '', bool $injection = false): array
    {
        $mail = Mails::mail($key, conversation: $conversation);
        $recorder = new MailIntakeRecorder($this->store);
        $recorded = $recorder->recordMessage(new NewMessage(Mails::MAILBOX, $mail->graphMessageId, $mail->internetMessageId, $mail->conversationId, $mail->subject, $mail->from, $mail->to, $mail->cc, $mail->receivedAt, $mail->bodyPreview, false, []));
        $recorder->recordAssessment(Mails::MAILBOX, $mail->internetMessageId, NewAssessment::classified($label, 0.9, 'because', 'model', 'v1', $this->now, 10, $injection));

        return ['threadId' => $recorded->threadId, 'messageId' => $recorded->messageId];
    }

    private function thread(string $id): ThreadRecord
    {
        $thread = $this->store->thread($id);
        self::assertNotNull($thread);

        return $thread;
    }

    public function test_an_rfq_becomes_a_linked_draft_and_the_thread_is_drafted(): void
    {
        $m = $this->receive('a', 'rfq');

        self::assertSame(MailDraftAutomation::CREATED, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', false));

        $thread = $this->thread($m['threadId']);
        self::assertSame('drafted', $thread->summary->reviewState);
        self::assertCount(1, $thread->links);
        self::assertSame('rfq_draft', $thread->links[0]->kind);
        self::assertSame([['threadId' => $m['threadId'], 'messageId' => $m['messageId']]], $this->creator->calls);
        self::assertSame('draft_created', array_map(static fn ($e): string => $e->kind, $thread->activity)[count($thread->activity) - 1]);
    }

    public function test_asking_again_never_makes_or_links_a_second_draft(): void
    {
        $m = $this->receive('a', 'rfq');
        $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', false);

        // A retry, an overlapping pass and Graph redelivering all arrive as the same call. (The label does
        // not matter: the message made the draft, and is not its own follow-up.)
        self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', false));
        self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq_followup', false));

        self::assertCount(1, $this->creator->made);
        self::assertCount(1, $this->thread($m['threadId'])->links, 'the one draft link: the message that made it is not its own follow-up');
        self::assertCount(1, $this->creator->calls, 'the thread already had its draft, so the creator was not asked again');
    }

    public function test_a_followup_joins_the_draft_the_conversation_already_has_and_changes_nothing_else(): void
    {
        $first = $this->receive('a', 'rfq', 'conv-1');
        $this->automation->apply($first['threadId'], $first['messageId'], 'rfq', false);
        $second = $this->receive('b', 'rfq_followup', 'conv-1');
        self::assertSame($first['threadId'], $second['threadId'], 'same conversation, same thread');

        self::assertSame(MailDraftAutomation::FOLLOWUP, $this->automation->apply($second['threadId'], $second['messageId'], 'rfq_followup', false));
        self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($second['threadId'], $second['messageId'], 'rfq_followup', false), 'once per message');

        $thread = $this->thread($first['threadId']);
        self::assertSame(['rfq_draft', 'rfq_followup'], array_map(static fn ($l): string => $l->kind, $thread->links));
        self::assertSame($second['messageId'], $thread->links[1]->messageId);
        self::assertSame($thread->links[0]->publicId, $thread->links[1]->publicId, 'it names the existing draft');
        self::assertSame('drafted', $thread->summary->reviewState);
        self::assertCount(1, $this->creator->made, 'no second draft');
        self::assertContains('followup_linked', array_map(static fn ($e): string => $e->kind, $thread->activity));
    }

    public function test_a_second_rfq_labelled_message_in_a_drafted_conversation_is_a_followup_not_a_second_draft(): void
    {
        $first = $this->receive('a', 'rfq', 'conv-1');
        $this->automation->apply($first['threadId'], $first['messageId'], 'rfq', false);
        $second = $this->receive('b', 'rfq', 'conv-1');

        self::assertSame(MailDraftAutomation::FOLLOWUP, $this->automation->apply($second['threadId'], $second['messageId'], 'rfq', false));
        self::assertCount(1, $this->creator->made);
    }

    public function test_a_followup_with_no_draft_is_left_for_a_person(): void
    {
        $m = $this->receive('a', 'rfq_followup');

        self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq_followup', false));

        self::assertSame([], $this->thread($m['threadId'])->links);
        self::assertSame('open', $this->thread($m['threadId'])->summary->reviewState);
        self::assertSame([], $this->creator->calls);
    }

    public function test_uncertain_and_not_rfq_are_not_touched(): void
    {
        foreach (['uncertain', 'not_rfq'] as $label) {
            $m = $this->receive($label, $label);
            self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($m['threadId'], $m['messageId'], $label, false));
            self::assertSame('open', $this->thread($m['threadId'])->summary->reviewState);
        }
        self::assertSame([], $this->creator->calls);
    }

    public function test_a_message_flagged_for_injection_never_gets_an_automatic_draft(): void
    {
        $m = $this->receive('a', 'rfq', injection: true);

        self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', true));

        self::assertSame([], $this->creator->calls);
        self::assertSame('open', $this->thread($m['threadId'])->summary->reviewState);
    }

    public function test_a_conversation_a_person_has_ignored_is_left_alone(): void
    {
        $m = $this->receive('a', 'rfq');
        $this->store->recordIgnore($m['threadId'], new MailParty('Sam', 'sam@tricab.com'), 7, null, $this->now);

        self::assertSame(MailDraftAutomation::NOTHING, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', false));
        self::assertSame([], $this->creator->calls);
    }

    public function test_a_refused_draft_changes_nothing_and_can_be_retried(): void
    {
        $m = $this->receive('a', 'rfq');
        $this->creator->failures = 1;

        try {
            $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', false);
            self::fail('expected MailUnavailable');
        } catch (MailUnavailable) {
        }
        self::assertSame('open', $this->thread($m['threadId'])->summary->reviewState);
        self::assertSame([], $this->thread($m['threadId'])->links);

        self::assertSame(MailDraftAutomation::CREATED, $this->automation->apply($m['threadId'], $m['messageId'], 'rfq', false));
        self::assertCount(1, $this->creator->made);
    }
}
