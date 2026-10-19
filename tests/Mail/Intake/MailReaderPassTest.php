<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Intake\ClassifierFailed;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailReaderOptions;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Mail\Intake\PassResult;
use TriCab\Core\Shared\Mailbox\MailboxAccessDenied;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxSyncExpired;

/** One pass end to end over fakes: Graph (scripted), the mail store (in memory), the classifier (scripted). */
final class MailReaderPassTest extends TestCase
{
    private ScriptedDeltaSource $source;

    private InMemoryMailboxReader $graph;

    private InMemoryMailStore $store;

    private ScriptedClassifier $classifier;

    private MemoryReaderState $state;

    /** @var list<string> */
    private array $log = [];

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->source = new ScriptedDeltaSource();
        $this->graph = new InMemoryMailboxReader();
        $this->store = new InMemoryMailStore();
        $this->classifier = new ScriptedClassifier();
        $this->state = new MemoryReaderState();
        $this->now = new DateTimeImmutable('2026-10-18 10:00:00 UTC');
    }

    private function pass(string $mode = 'shadow', bool $read = true, ?MailStore $store = null, int $passSeconds = 100): MailReaderPass
    {
        return new MailReaderPass(
            new MailReaderOptions(Mails::MAILBOX, $read, $mode, 2, $passSeconds, 360),
            $this->source,
            $this->graph,
            new MailIntakeRecorder($store ?? $this->store),
            $store ?? $this->store,
            $this->classifier,
            $this->state,
            [],
            function (string $line): void {
                $this->log[] = $line;
            },
            fn (): DateTimeImmutable => $this->now,
        );
    }

    /**
     * @param list<\TriCab\Core\Mail\Intake\IncomingMail> $mails
     * @param list<string> $removed
     */
    private function page(array $mails, string $link = 'delta-1', bool $complete = true, array $removed = []): MailChanges
    {
        foreach ($mails as $mail) {
            $this->graph->add(Mails::MAILBOX, Mails::body(substr($mail->graphMessageId, 6), hasAttachments: $mail->hasAttachments));
        }

        return new MailChanges($mails, $removed, $link, $complete);
    }

    private function threadCount(): int
    {
        return $this->store->listThreads(new ThreadQuery('all'))->total;
    }

    public function test_a_new_message_is_recorded_classified_and_the_cursor_moves_on(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')])];

        $result = $this->pass()->run();

        self::assertSame(PassResult::DONE, $result->status);
        self::assertSame(1, $result->recorded);
        self::assertSame(1, $result->classified);
        self::assertSame('delta-1', $this->state->cursor);
        self::assertTrue($this->state->complete);
        self::assertSame([], $this->state->pending, 'nothing is left half-done');
        $thread = $this->store->listThreads(new ThreadQuery('rfq'));
        self::assertSame(1, $thread->total);
        self::assertSame('connected', $this->state->connection['connection'] ?? null);
        self::assertSame('connected', $this->store->mailboxes()[0]->connection);
    }

    public function test_the_first_pass_starts_from_the_window_and_later_passes_from_the_cursor(): void
    {
        $this->pass()->run();
        self::assertNull($this->source->calls[0][0]);
        self::assertSame('2026-10-16T10:00:00+00:00', $this->source->calls[0][1]->format('c'), 'now minus 2 days');

        $this->pass()->run();
        self::assertSame('delta-empty', $this->source->calls[1][0], 'resumes from the saved delta link');
    }

    public function test_a_pass_with_no_new_mail_leaves_the_database_alone(): void
    {
        $this->state->saveConnection('connected', $this->now->modify('-10 minutes'));
        $store = $this->createMock(MailStore::class);
        $store->expects(self::never())->method(self::anything());

        $result = $this->pass(store: $store)->run();

        self::assertFalse($result->touchedDatabase);
        self::assertSame(0, $result->received);
    }

    public function test_the_heartbeat_touches_the_database_only_when_it_is_due(): void
    {
        $this->state->saveConnection('connected', $this->now->modify('-7 hours'));
        $result = $this->pass()->run();

        self::assertTrue($result->touchedDatabase);
        self::assertSame($this->now->format('c'), $this->store->mailboxes()[0]->lastSyncAt?->format('c'));
    }

    public function test_graph_redelivering_a_message_records_and_classifies_it_once(): void
    {
        $mail = Mails::mail('a');
        $this->source->pages = [$this->page([$mail]), $this->page([$mail->withHeaders([]), $mail])];

        $this->pass()->run();
        $second = $this->pass()->run();

        self::assertSame(2, $second->duplicates);
        self::assertSame(0, $second->recorded);
        self::assertCount(1, $this->classifier->inputs, 'one model call for one message');
        self::assertSame(1, $this->threadCount());
    }

    public function test_two_passes_that_overlap_still_record_a_message_once(): void
    {
        // The second pass starts before the first has moved the cursor: it is handed the same page.
        $mail = Mails::mail('a');
        $this->source->pages = [$this->page([$mail]), $this->page([$mail])];

        $this->pass()->run();
        $this->state->cursor = null;
        $this->pass()->run();

        self::assertSame(1, $this->threadCount());
        self::assertCount(1, $this->classifier->inputs);
    }

    public function test_a_message_recorded_by_a_pass_that_died_is_assessed_on_redelivery(): void
    {
        $mail = Mails::mail('a');
        $this->source->pages = [$this->page([$mail])];
        // The model call kills the pass after the message was recorded.
        $dying = new ScriptedClassifier();
        $dying->throw = new \RuntimeException('process died');
        $pass = new MailReaderPass(new MailReaderOptions(Mails::MAILBOX, true, 'shadow'), $this->source, $this->graph, new MailIntakeRecorder($this->store), $this->store, $dying, $this->state, [], null, fn (): DateTimeImmutable => $this->now);
        try {
            $pass->run();
            self::fail('expected the failure to propagate');
        } catch (\RuntimeException) {
        }
        self::assertNull($this->state->cursor, 'the page was not finished, so the cursor did not move');
        self::assertCount(1, $this->state->pending);

        $this->source->pages = [$this->page([$mail])];
        $result = $this->pass()->run();

        self::assertSame(1, $result->duplicates);
        self::assertSame(1, $result->classified, 'assessed this time');
        self::assertSame([], $this->state->pending);
    }

    public function test_pre_filter_skips_are_recorded_as_skipped_assessments_and_never_reach_the_model(): void
    {
        $this->source->pages = [$this->page([
            Mails::mail('own', from: Mails::MAILBOX),
            Mails::mail('bulk', headers: ['list-unsubscribe' => '<mailto:x@y.example>']),
            Mails::mail('ok'),
        ])];

        $result = $this->pass()->run();

        self::assertSame(2, $result->skipped);
        self::assertCount(1, $this->classifier->inputs);
        self::assertSame(2, $this->store->listThreads(new ThreadQuery('skipped'))->total);
        self::assertSame(1, $this->store->listThreads(new ThreadQuery('rfq'))->total);
    }

    public function test_headers_missing_from_the_delta_row_are_fetched_before_the_pre_filter(): void
    {
        $this->source->headers['graph-a'] = ['precedence' => 'bulk'];
        $this->source->pages = [$this->page([Mails::mail('a', headers: null)])];

        $result = $this->pass()->run();

        self::assertSame(1, $this->source->headerFetches);
        self::assertSame(1, $result->skipped);
    }

    public function test_attachment_metadata_is_recorded_without_content_and_an_ics_is_skipped(): void
    {
        $this->graph->add(Mails::MAILBOX, Mails::body('cal', hasAttachments: true), [new MailboxAttachment(new MailboxAttachmentSummary('att-1', 'meeting.ics', 'text/calendar', 800), 'BEGIN:VCALENDAR')]);
        $this->graph->add(Mails::MAILBOX, Mails::body('pdf', hasAttachments: true), [new MailboxAttachment(new MailboxAttachmentSummary('att-2', 'enquiry.pdf', 'application/pdf', 52000), '%PDF-bytes'), new MailboxAttachment(new MailboxAttachmentSummary('att-3', 'logo.png', 'image/png', 900, true), 'png')]);
        $this->source->pages = [new MailChanges([Mails::mail('cal', hasAttachments: true), Mails::mail('pdf', hasAttachments: true)], [], 'delta-1', true)];

        $result = $this->pass()->run();

        self::assertSame(1, $result->skipped);
        self::assertSame([['name' => 'enquiry.pdf', 'type' => 'application/pdf']], $this->classifier->inputs[0]->attachments, 'inline images are not offered to the model');
        $detail = $this->store->listThreads(new ThreadQuery('rfq'))->items[0];
        $record = $this->store->thread($detail->id);
        self::assertNotNull($record);
        self::assertSame('enquiry.pdf', $record->messages[0]->attachments[0]->fileName);
    }

    public function test_the_classifier_sees_the_body_as_trimmed_plain_text_and_it_is_not_stored(): void
    {
        $long = '<style>p{color:red}</style><p>Please quote</p><p>' . str_repeat('x', 5000) . '</p>';
        $this->graph->add(Mails::MAILBOX, Mails::body('a', $long));
        $this->source->pages = [new MailChanges([Mails::mail('a')], [], 'delta-1', true)];

        $this->pass()->run();

        $text = $this->classifier->inputs[0]->bodyText;
        self::assertSame(2000, mb_strlen($text));
        self::assertStringStartsWith("Please quote\nxxx", $text);
        self::assertStringNotContainsString('color:red', $text);
        $thread = $this->store->thread($this->store->listThreads(new ThreadQuery('rfq'))->items[0]->id);
        self::assertStringNotContainsString('xxxxxxxx', serialize($thread), 'only the preview is stored');
    }

    public function test_a_failed_model_call_is_recorded_as_uncertain_with_the_failure_as_the_reason(): void
    {
        $this->classifier->answers = [new ClassifierFailed('the model could not be reached or took too long', 'claude-haiku-5-5', 'rfq-mail-classify-v1', 30000)];
        $this->source->pages = [$this->page([Mails::mail('a')])];

        $result = $this->pass()->run();

        self::assertSame(1, $result->uncertainFailures);
        $summary = $this->store->listThreads(new ThreadQuery('uncertain'))->items[0];
        $record = $this->store->thread($summary->id);
        self::assertNotNull($record);
        self::assertSame('uncertain', $record->assessment->label);
        self::assertStringContainsString('took too long', $record->assessment->reason);
        self::assertSame('claude-haiku-5-5', $record->assessment->model);
        self::assertSame(30000, $record->assessment->latencyMs);
    }

    public function test_a_result_carries_model_latency_version_and_injection_flag(): void
    {
        $this->classifier->answers = [new \TriCab\Core\Mail\Intake\ClassifierVerdict('rfq_followup', 0.81, 'Chases a quote.', true, 'claude-haiku-5-5', 'rfq-mail-classify-v1', 912)];
        $this->source->pages = [$this->page([Mails::mail('a')])];

        $this->pass()->run();

        $record = $this->store->thread($this->store->listThreads(new ThreadQuery('rfq_followup'))->items[0]->id);
        self::assertNotNull($record);
        self::assertSame(912, $record->assessment->latencyMs);
        self::assertSame('rfq-mail-classify-v1', $record->assessment->version);
        self::assertTrue($record->assessment->injectionSuspected);
        self::assertEqualsWithDelta(0.81, $record->assessment->confidence, 0.001);
    }

    public function test_a_conversation_with_a_draft_is_flagged_to_the_classifier(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a', conversation: 'conv-1')])];
        $this->pass()->run();
        self::assertFalse($this->classifier->inputs[0]->conversationHasDraft);

        $threadId = $this->store->listThreads(new ThreadQuery('all'))->items[0]->id;
        $this->store->recordDraft($threadId, new \TriCab\Core\Mail\Application\DTO\NewLink('rfq-1', 'RFQ-1', 'Cable', 7, $this->now), new \TriCab\Core\Mail\Application\DTO\MailParty('Sam', 'sam@tricab.com'));
        $this->source->pages = [$this->page([Mails::mail('b', conversation: 'conv-1')])];
        $this->pass()->run();

        self::assertTrue($this->classifier->inputs[1]->conversationHasDraft);
    }

    public function test_an_expired_token_410_resyncs_from_the_window_and_records_nothing_twice(): void
    {
        $mail = Mails::mail('a');
        $this->source->pages = [$this->page([$mail], 'delta-1')];
        $this->pass()->run();

        // Graph says the saved token is dead, then serves the window again, including the known message.
        $this->source->pages = [new MailboxSyncExpired('gone'), $this->page([$mail, Mails::mail('b')], 'delta-2')];
        $result = $this->pass()->run();

        self::assertTrue($result->resynced);
        self::assertNull($this->source->calls[2][0], 'restarted without a token');
        self::assertSame('delta-2', $this->state->cursor);
        self::assertSame(1, $result->duplicates);
        self::assertSame(2, $this->threadCount());
        self::assertCount(2, $this->classifier->inputs);
    }

    public function test_a_410_on_the_very_first_query_is_not_retried_forever(): void
    {
        $this->source->pages = [new MailboxSyncExpired('gone')];
        $this->expectException(MailboxSyncExpired::class);
        $this->pass()->run();
    }

    public function test_removed_messages_are_counted_and_change_nothing(): void
    {
        $this->source->pages = [new MailChanges([], ['graph-gone'], 'delta-1', true)];
        $result = $this->pass()->run();

        self::assertSame(1, $result->removed);
        self::assertSame(0, $this->threadCount());
        self::assertSame('delta-1', $this->state->cursor);
    }

    public function test_paging_follows_next_links_and_saves_the_cursor_after_each_finished_page(): void
    {
        $this->source->pages = [
            $this->page([Mails::mail('a')], 'next-1', false),
            $this->page([Mails::mail('b')], 'next-2', false),
            $this->page([Mails::mail('c')], 'delta-final', true),
        ];
        $result = $this->pass()->run();

        self::assertSame(3, $result->pages);
        self::assertSame(['next-1', 'next-2'], [$this->source->calls[1][0], $this->source->calls[2][0]]);
        self::assertSame('delta-final', $this->state->cursor);
        self::assertTrue($this->state->complete);
        self::assertSame(3, $this->state->cursorWrites);
    }

    public function test_a_pass_stops_between_pages_when_its_time_is_up_and_resumes_from_the_next_link(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')], 'next-1', false), $this->page([Mails::mail('b')], 'next-2', false)];
        $clock = [0];
        $pass = new MailReaderPass(new MailReaderOptions(Mails::MAILBOX, true, 'shadow', 2, 10), $this->source, $this->graph, new MailIntakeRecorder($this->store), $this->store, $this->classifier, $this->state, [], null, function () use (&$clock): DateTimeImmutable {
            return $this->now->modify('+' . ($clock[0]++ * 6) . ' seconds');
        });

        $result = $pass->run();

        self::assertSame(1, $result->pages, 'the budget was spent on the first page');
        self::assertSame('next-1', $this->state->cursor);
        self::assertFalse($this->state->complete);

        // The next pass carries on from the saved next link.
        $this->pass()->run();
        self::assertSame('next-1', $this->source->calls[1][0]);
        self::assertSame('next-2', $this->state->cursor);
    }

    public function test_a_graph_failure_keeps_the_cursor_and_marks_the_mailbox_in_error(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')], 'delta-1')];
        $this->pass()->run();

        $this->source->pages = [new MailboxAccessDenied('refused')];
        try {
            $this->pass()->run();
            self::fail('expected the Graph failure');
        } catch (MailboxAccessDenied) {
        }

        self::assertSame('delta-1', $this->state->cursor);
        self::assertSame('error', $this->state->connection['connection'] ?? null);
        self::assertSame('error', $this->store->mailboxes()[0]->connection);
    }

    public function test_mode_off_does_nothing_at_all(): void
    {
        $store = $this->createMock(MailStore::class);
        $store->expects(self::never())->method(self::anything());

        $result = $this->pass('off', true, $store)->run();

        self::assertSame(PassResult::OFF, $result->status);
        self::assertSame([], $this->source->calls, 'Graph was not asked either');
    }

    public function test_the_pause_switch_stops_reading_and_a_reader_never_switched_on_touches_nothing(): void
    {
        $store = $this->createMock(MailStore::class);
        $store->expects(self::never())->method(self::anything());

        $result = $this->pass('shadow', false, $store)->run();

        self::assertSame(PassResult::PAUSED, $result->status);
        self::assertSame([], $this->source->calls);
    }

    public function test_pausing_a_connected_reader_records_paused_once(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')])];
        $this->pass()->run();

        $this->pass('shadow', false)->run();
        self::assertSame('paused', $this->store->mailboxes()[0]->connection);
        $this->now = $this->now->modify('+1 minute');
        $this->pass('shadow', false)->run();
        self::assertSame('paused', $this->state->connection['connection'] ?? null);
        self::assertSame(1, count($this->source->calls), 'no Graph call while paused');
    }

    public function test_live_mode_behaves_as_shadow_with_a_warning(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')])];
        $result = $this->pass('live')->run();

        self::assertSame(1, $result->classified);
        self::assertCount(1, array_filter($this->log, static fn (string $l): bool => str_contains($l, 'drafts are not built yet')));
        $record = $this->store->thread($this->store->listThreads(new ThreadQuery('rfq'))->items[0]->id);
        self::assertNotNull($record);
        self::assertSame([], $record->links, 'no draft is made');
    }
}
