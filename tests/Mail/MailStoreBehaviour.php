<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Mail\Application\DTO\ExportQuery;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\NewDecision;
use TriCab\Core\Mail\Application\DTO\NewLink;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailActionBlocked;
use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;

/**
 * What every {@see MailStore} must do: queues, counts, filters, search, sorting, paging, history, and the
 * summary a thread shows. Run against the in-memory store always, and against local SQL Server when asked
 * (`AzureSqlMailStoreIntegrationTest`), so the SQL states the same rules the in-memory store does.
 */
abstract class MailStoreBehaviour extends TestCase
{
    protected MailStore $store;

    protected MailIntakeRecorder $recorder;

    abstract protected function newStore(): MailStore;

    protected function setUp(): void
    {
        $this->store = $this->newStore();
        $this->recorder = new MailIntakeRecorder($this->store);
    }

    private function decide(string $threadId, string $kind, string $label, string $at = '2026-10-18 12:00:00'): void
    {
        $record = $this->store->thread($threadId);
        self::assertNotNull($record);
        $this->store->recordDecision($threadId, new NewDecision(
            $record->assessment->id,
            $kind,
            $label,
            7,
            new MailParty('Sam Sales', 'sam@tricab.com'),
            $kind === 'correct' ? 'wrong one' : null,
            MailFixtures::at($at),
        ));
    }

    /** One thread of every kind, newest first: u1 (uncertain, unread), r1, f1, n1, s1 (skipped), d1 (uncertain corrected to rfq). */
    private function seed(): void
    {
        MailFixtures::classified($this->recorder, 'u1', 'uncertain', 0.4, at: '2026-10-18 09:00:00', subject: 'Cable?', fromName: 'Una Unsure', fromAddress: 'una@x.example');
        MailFixtures::classified($this->recorder, 'r1', 'rfq', 0.95, at: '2026-10-18 08:00:00', isRead: true, subject: 'RFQ 100m SDI', fromName: 'Rob Buyer', fromAddress: 'rob@x.example', attachments: [new NewAttachment('att-1', 'list.xlsx', 'application/vnd.ms-excel', 2048)]);
        MailFixtures::classified($this->recorder, 'f1', 'rfq_followup', 0.8, at: '2026-10-18 07:00:00', subject: 'Re: price', fromName: 'Fay Followup', fromAddress: 'fay@x.example');
        MailFixtures::classified($this->recorder, 'n1', 'not_rfq', 0.99, at: '2026-10-18 06:00:00', isRead: true, subject: 'Invoice 4411', fromName: 'Nat Notrfq', fromAddress: 'nat@x.example');
        MailFixtures::skipped($this->recorder, 's1', '2026-10-18 05:00:00');
        $d1 = MailFixtures::classified($this->recorder, 'd1', 'uncertain', 0.5, at: '2026-10-18 04:00:00', subject: 'Maybe a quote', fromName: 'Dee Decided', fromAddress: 'dee@x.example');
        $this->decide($d1->threadId, 'correct', 'rfq');
    }

    public function test_a_thread_is_in_no_list_until_it_has_been_assessed(): void
    {
        $this->recorder->recordMessage(MailFixtures::message('m1'));

        self::assertSame(0, $this->store->listThreads(new ThreadQuery())->total);
        self::assertSame(['total' => 0, 'unread' => 0], $this->store->queueCounts()['all']);

        $this->recorder->recordAssessment(MailFixtures::MAILBOX, '<m1@customer.example>', NewAssessment::classified('rfq', 0.9, 'x', 'm', 'v', MailFixtures::at()));
        self::assertSame(1, $this->store->listThreads(new ThreadQuery())->total);
    }

    public function test_graph_delivering_a_message_again_changes_nothing(): void
    {
        $first = $this->recorder->recordMessage(MailFixtures::message('dup'));
        $second = $this->recorder->recordMessage(MailFixtures::message('dup', subject: 'Changed subject'));

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame($first->messageId, $second->messageId);
        self::assertSame($first->threadId, $second->threadId);

        $this->recorder->recordAssessment(MailFixtures::MAILBOX, '<dup@customer.example>', NewAssessment::classified('rfq', 0.9, 'x', 'm', 'v', MailFixtures::at()));
        $record = $this->store->thread($first->threadId);
        self::assertNotNull($record);
        self::assertCount(1, $record->messages);
        self::assertSame('Quote for 100m of 3C 2.5mm', $record->summary->subject);
        self::assertCount(1, array_filter($record->activity, static fn ($a): bool => $a->kind === 'received'));
    }

    public function test_messages_of_one_conversation_share_a_thread_and_the_row_shows_the_customer(): void
    {
        $first = MailFixtures::classified($this->recorder, 'c1', 'rfq', 0.9, conversation: 'conv-x', at: '2026-10-18 09:00:00', isRead: true);
        // Our own reply, then the customer's follow-up.
        $this->recorder->recordMessage(MailFixtures::message('c2', 'conv-x', 'Re: Quote', '2026-10-18 10:00:00', 'TriCab Melbourne', MailFixtures::MAILBOX, isRead: true, preview: 'Our reply'));
        $third = $this->recorder->recordMessage(MailFixtures::message('c3', 'conv-x', 'Re: Quote', '2026-10-18 11:00:00', 'Jo Customer', 'jo@customer.example', preview: 'Customer follow-up'));

        self::assertSame($first->threadId, $third->threadId);
        $this->recorder->recordAssessment(MailFixtures::MAILBOX, '<c3@customer.example>', NewAssessment::classified('rfq_followup', 0.7, 'A follow-up.', 'm', 'v', MailFixtures::at('2026-10-18 11:01:00')));

        $record = $this->store->thread($first->threadId);
        self::assertNotNull($record);
        self::assertSame(3, $record->summary->messageCount);
        self::assertSame('Quote for 100m of 3C 2.5mm', $record->summary->subject, 'the first message names the thread');
        self::assertSame('jo@customer.example', $record->summary->from->address);
        self::assertSame('Customer follow-up', $record->summary->preview);
        self::assertSame('rfq_followup', $record->summary->label, 'the newest assessed message decides');
        self::assertTrue($record->summary->unread, 'one message is unread');
        self::assertSame(['c1@customer.example', 'c2@customer.example', 'c3@customer.example'], array_map(static fn ($m): string => trim($m->internetMessageId, '<>'), $record->messages));
        self::assertSame('2026-10-18T11:00:00.000Z', $record->summary->latestAt->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_an_assessment_is_history_and_the_latest_wins(): void
    {
        $m = MailFixtures::classified($this->recorder, 'h1', 'uncertain', 0.4, at: '2026-10-18 09:00:00');
        $this->recorder->recordAssessment(MailFixtures::MAILBOX, '<h1@customer.example>', NewAssessment::classified('rfq', 0.92, 'On a re-run.', 'm2', 'v2', MailFixtures::at('2026-10-18 10:00:00')));

        $record = $this->store->thread($m->threadId);
        self::assertNotNull($record);
        self::assertSame('rfq', $record->assessment->label);
        self::assertSame('m2', $record->assessment->model);
        self::assertSame(['received', 'classified', 'classified'], array_map(static fn ($a): string => $a->kind, $record->activity));
    }

    public function test_a_skipped_message_says_why_and_has_no_model(): void
    {
        $s = MailFixtures::skipped($this->recorder, 's1');
        $record = $this->store->thread($s->threadId);

        self::assertNotNull($record);
        self::assertTrue($record->assessment->skipped);
        self::assertTrue($record->summary->skipped);
        self::assertSame('Auto-reply', $record->assessment->reason);
        self::assertSame('pre-filter', $record->assessment->model);
        self::assertContains('skipped', array_map(static fn ($a): string => $a->kind, $record->activity));
    }

    public function test_queue_counts(): void
    {
        $this->seed();
        $counts = $this->store->queueCounts();

        self::assertSame(['all', 'uncertain', 'rfq', 'rfq_followup', 'not_rfq', 'skipped', 'marked'], array_keys($counts));
        self::assertSame(['total' => 6, 'unread' => 4], $counts['all']);
        // d1 was corrected to rfq, so it leaves Uncertain and joins RFQ; s1 is skipped and in no label queue.
        self::assertSame(['total' => 1, 'unread' => 1], $counts['uncertain']);
        self::assertSame(['total' => 2, 'unread' => 1], $counts['rfq']);
        self::assertSame(['total' => 1, 'unread' => 1], $counts['rfq_followup']);
        self::assertSame(['total' => 1, 'unread' => 0], $counts['not_rfq']);
        self::assertSame(['total' => 1, 'unread' => 1], $counts['skipped']);
        self::assertSame(['total' => 1, 'unread' => 1], $counts['marked']);
    }

    public function test_each_queue_lists_its_threads_newest_first(): void
    {
        $this->seed();
        $subjects = fn (string $queue): array => array_map(static fn ($t): string => $t->subject, $this->store->listThreads(new ThreadQuery(queue: $queue))->items);

        self::assertSame(['Cable?', 'RFQ 100m SDI', 'Re: price', 'Invoice 4411', 'Automatic reply: out of office', 'Maybe a quote'], $subjects('all'));
        self::assertSame(['Cable?'], $subjects('uncertain'));
        self::assertSame(['RFQ 100m SDI', 'Maybe a quote'], $subjects('rfq'));
        self::assertSame(['Automatic reply: out of office'], $subjects('skipped'));
        self::assertSame(['Maybe a quote'], $subjects('marked'));
    }

    public function test_a_decision_changes_the_effective_label_and_not_the_assessment(): void
    {
        $this->seed();
        $row = null;
        foreach ($this->store->listThreads(new ThreadQuery(queue: 'marked'))->items as $t) {
            $row = $t;
        }

        self::assertNotNull($row);
        self::assertSame('uncertain', $row->label);
        self::assertSame('rfq', $row->effectiveLabel);
        self::assertTrue($row->decided);
        self::assertEqualsWithDelta(0.5, $row->confidence, 0.0001);
    }

    public function test_the_latest_decision_wins_and_every_one_is_in_the_activity(): void
    {
        $m = MailFixtures::classified($this->recorder, 'k1', 'uncertain', 0.4);
        $this->decide($m->threadId, 'confirm', 'uncertain', '2026-10-18 12:00:00');
        $this->decide($m->threadId, 'correct', 'not_rfq', '2026-10-18 13:00:00');

        $record = $this->store->thread($m->threadId);
        self::assertNotNull($record);
        self::assertNotNull($record->decision);
        self::assertSame('correct', $record->decision->kind);
        self::assertSame('not_rfq', $record->summary->effectiveLabel);
        self::assertSame('Sam Sales', $record->decision->decidedBy->name);
        self::assertSame(
            ['received', 'classified', 'label_confirmed', 'label_corrected'],
            array_map(static fn ($a): string => $a->kind, $record->activity),
        );
        self::assertStringContainsString('Corrected the label from uncertain to not an RFQ', $record->activity[3]->description);
    }

    public function test_filters_on_unread_and_attachments(): void
    {
        $this->seed();
        $ids = fn (ThreadQuery $q): array => array_map(static fn ($t): string => $t->subject, $this->store->listThreads($q)->items);

        self::assertSame(['Cable?', 'Re: price', 'Automatic reply: out of office', 'Maybe a quote'], $ids(new ThreadQuery(unreadOnly: true)));
        self::assertSame(['RFQ 100m SDI'], $ids(new ThreadQuery(attachments: 'with')));
        self::assertCount(5, $ids(new ThreadQuery(attachments: 'without')));
        self::assertSame([], $ids(new ThreadQuery(queue: 'not_rfq', unreadOnly: true)));
    }

    public function test_search_looks_at_subject_sender_address_and_preview_and_treats_wildcards_literally(): void
    {
        $this->seed();
        $found = fn (string $needle): array => array_map(static fn ($t): string => $t->subject, $this->store->listThreads(new ThreadQuery(search: $needle))->items);

        self::assertSame(['Invoice 4411'], $found('4411'));
        self::assertSame(['Re: price'], $found('Fay'));
        self::assertSame(['RFQ 100m SDI'], $found('ROB@X.EXAMPLE'), 'case is ignored');
        self::assertCount(6, $found('could you quote'), 'the preview is searched');
        self::assertSame([], $found('%'));
        self::assertSame([], $found('_'));
        self::assertSame([], $found('[a-z]'));
    }

    public function test_sorting(): void
    {
        $this->seed();
        $by = fn (string $sort): array => array_map(static fn ($t): string => $t->from->name, $this->store->listThreads(new ThreadQuery(sort: $sort))->items);

        self::assertSame(['Maybe a quote', 'Automatic reply: out of office', 'Invoice 4411', 'Re: price', 'RFQ 100m SDI', 'Cable?'], array_map(static fn ($t): string => $t->subject, $this->store->listThreads(new ThreadQuery(sort: 'oldest'))->items));
        self::assertSame(['Auto', 'Dee Decided', 'Fay Followup', 'Nat Notrfq', 'Rob Buyer', 'Una Unsure'], $by('sender'));
        $confidence = array_map(static fn ($t): float => $t->confidence, $this->store->listThreads(new ThreadQuery(sort: 'confidence'))->items);
        $sorted = $confidence;
        rsort($sorted);
        self::assertSame($sorted, $confidence);
    }

    public function test_paging_walks_every_thread_once_with_an_opaque_cursor(): void
    {
        $this->seed();
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->store->listThreads(ThreadQuery::fromInput(['pageSize' => '4'] + ($cursor === null ? [] : ['cursor' => $cursor])));
            self::assertSame(6, $page->total);
            foreach ($page->items as $item) {
                $seen[] = $item->subject;
            }
            $cursor = $page->nextCursor;
            ++$pages;
        } while ($cursor !== null && $pages < 5);

        self::assertSame(2, $pages);
        self::assertCount(6, array_unique($seen));
        self::assertSame($seen[0], 'Cable?');
    }

    public function test_the_cursor_is_validated(): void
    {
        $this->expectException(MailInputInvalid::class);
        ThreadQuery::fromInput(['cursor' => 'not-a-cursor!!']);
    }

    public function test_marking_read_and_unread_covers_every_message_of_the_thread(): void
    {
        $a = MailFixtures::classified($this->recorder, 'mr1', 'rfq', 0.9, conversation: 'conv-mr', at: '2026-10-18 09:00:00');
        $this->recorder->recordMessage(MailFixtures::message('mr2', 'conv-mr', 'Re: x', '2026-10-18 10:00:00'));

        $this->store->markRead($a->threadId, true);
        self::assertFalse($this->store->thread($a->threadId)?->summary->unread);
        self::assertSame(['total' => 1, 'unread' => 0], $this->store->queueCounts()['all']);

        $this->store->markRead($a->threadId, false);
        self::assertTrue($this->store->thread($a->threadId)?->summary->unread);
    }

    public function test_a_thread_is_drafted_or_ignored_once(): void
    {
        $a = MailFixtures::classified($this->recorder, 'dr1', 'uncertain', 0.4);
        $b = MailFixtures::classified($this->recorder, 'ig1', 'uncertain', 0.4);
        $actor = new MailParty('Sam Sales', 'sam@tricab.com');

        $this->store->recordDraft($a->threadId, new NewLink('11111111-2222-3333-4444-555555555555', 'RFQ-D-9', 'Quote for cable', 7, MailFixtures::at('2026-10-18 12:00:00')), $actor);
        $record = $this->store->thread($a->threadId);
        self::assertNotNull($record);
        self::assertSame('drafted', $record->summary->reviewState);
        self::assertSame('RFQ-D-9', $record->links[0]->reference);
        self::assertSame('draft_created', $record->activity[count($record->activity) - 1]->kind);

        $this->store->recordIgnore($b->threadId, $actor, 7, 'spam', MailFixtures::at('2026-10-18 12:00:00'));
        $ignored = $this->store->thread($b->threadId);
        self::assertNotNull($ignored);
        self::assertSame('ignored', $ignored->summary->reviewState);
        self::assertSame('Ignored: spam', $ignored->activity[count($ignored->activity) - 1]->description);

        $this->expectException(MailActionBlocked::class);
        $this->store->recordIgnore($a->threadId, $actor, 7, null, MailFixtures::at('2026-10-18 13:00:00'));
    }

    public function test_a_deleted_thread_is_in_no_list_count_export_or_read(): void
    {
        $this->seed();
        $gone = MailFixtures::classified($this->recorder, 'del1', 'rfq', 0.9, at: '2026-10-18 10:00:00', attachments: [new NewAttachment('graph-att-d', 'list.pdf', 'application/pdf', 100)]);
        $file = $this->store->thread($gone->threadId)?->messages[0]->attachments[0];
        self::assertNotNull($file);
        self::assertSame(7, $this->store->listThreads(new ThreadQuery())->total);

        $this->store->deleteThread($gone->threadId, new MailParty('Sam Sales', 'sam@tricab.com'), 7, MailFixtures::at('2026-10-18 12:00:00'));

        self::assertNull($this->store->thread($gone->threadId));
        self::assertNull($this->store->messageRef($gone->messageId));
        self::assertNull($this->store->attachmentRef($file->id));
        self::assertSame(6, $this->store->listThreads(new ThreadQuery())->total);
        self::assertSame(2, $this->store->listThreads(new ThreadQuery(queue: 'rfq'))->total, 'r1 and d1 are left in RFQ');
        self::assertSame(['total' => 6, 'unread' => 4], $this->store->queueCounts()['all']);
        self::assertSame(['total' => 2, 'unread' => 1], $this->store->queueCounts()['rfq']);
        self::assertNotContains($gone->threadId, array_map(static fn ($r): string => $r->threadId, $this->store->exportRows(new ExportQuery())));
        self::assertSame(0, $this->store->listThreads(new ThreadQuery(search: 'Quote for 100m'))->total);

        // Deleting again changes nothing.
        $this->store->deleteThread($gone->threadId, new MailParty('Sam Sales', 'sam@tricab.com'), 7, MailFixtures::at('2026-10-18 13:00:00'));
        self::assertSame(6, $this->store->listThreads(new ThreadQuery())->total);
    }

    public function test_graph_delivering_a_deleted_threads_mail_again_does_not_bring_it_back(): void
    {
        $gone = MailFixtures::classified($this->recorder, 'del2', 'rfq', 0.9);
        $this->store->deleteThread($gone->threadId, new MailParty('Sam Sales', 'sam@tricab.com'), 7, MailFixtures::at('2026-10-18 12:00:00'));

        // As after a deploy empties the reader's cursor: the same message, recorded and assessed again.
        $again = $this->recorder->recordMessage(MailFixtures::message('del2'));
        $this->recorder->recordAssessment(MailFixtures::MAILBOX, '<del2@customer.example>', NewAssessment::classified('rfq', 0.9, 'x', 'm', 'v', MailFixtures::at('2026-10-18 12:30:00')));

        self::assertFalse($again->created);
        self::assertNull($this->store->thread($gone->threadId));
        self::assertSame(0, $this->store->listThreads(new ThreadQuery())->total);
    }

    public function test_a_new_message_in_a_deleted_conversation_brings_it_back(): void
    {
        $gone = MailFixtures::classified($this->recorder, 'del3', 'rfq', 0.9, conversation: 'conv-del', at: '2026-10-18 09:00:00');
        $this->store->deleteThread($gone->threadId, new MailParty('Sam Sales', 'sam@tricab.com'), 7, MailFixtures::at('2026-10-18 12:00:00'));

        $reply = MailFixtures::classified($this->recorder, 'del4', 'rfq_followup', 0.8, conversation: 'conv-del', at: '2026-10-18 14:00:00');

        self::assertSame($gone->threadId, $reply->threadId);
        $record = $this->store->thread($gone->threadId);
        self::assertNotNull($record);
        self::assertSame(2, $record->summary->messageCount);
        self::assertSame(1, $this->store->listThreads(new ThreadQuery())->total);
    }

    public function test_attachments_list_only_real_files_and_resolve_to_graph_ids(): void
    {
        $m = MailFixtures::classified($this->recorder, 'at1', 'rfq', 0.9, attachments: [
            new NewAttachment('graph-att-1', 'drawing.pdf', 'application/pdf', 1500),
            new NewAttachment('graph-att-2', 'logo.png', 'image/png', 900, true),
        ]);
        $record = $this->store->thread($m->threadId);

        self::assertNotNull($record);
        self::assertTrue($record->summary->hasAttachments);
        self::assertCount(1, $record->messages[0]->attachments);
        $file = $record->messages[0]->attachments[0];
        self::assertSame('drawing.pdf', $file->fileName);
        self::assertSame($m->messageId, $file->messageId);

        $ref = $this->store->attachmentRef($file->id);
        self::assertNotNull($ref);
        self::assertSame(['melbourne@tricab.com', 'graph-at1', 'graph-att-1', 1500], [$ref->mailboxAddress, $ref->graphMessageId, $ref->graphAttachmentId, $ref->sizeBytes]);
        self::assertNull($this->store->attachmentRef('nope'));

        $onlyInline = MailFixtures::classified($this->recorder, 'at2', 'rfq', 0.9, attachments: [new NewAttachment('g', 'sig.png', 'image/png', 10, true)]);
        self::assertFalse($this->store->thread($onlyInline->threadId)?->summary->hasAttachments);
    }

    public function test_a_message_resolves_to_where_its_body_lives(): void
    {
        $m = MailFixtures::classified($this->recorder, 'ref1', 'rfq', 0.9);
        $ref = $this->store->messageRef($m->messageId);

        self::assertNotNull($ref);
        self::assertSame(['melbourne@tricab.com', 'graph-ref1'], [$ref->mailboxAddress, $ref->graphMessageId]);
        self::assertNull($this->store->messageRef('nope'));
    }

    public function test_the_mailbox_shows_its_connection_and_last_sync(): void
    {
        $this->recorder->recordSync(MailFixtures::MAILBOX, 'error', MailFixtures::at('2026-10-18 09:30:00'), 'TriCab Melbourne');
        $mailboxes = $this->store->mailboxes();

        self::assertCount(1, $mailboxes);
        self::assertSame(['melbourne@tricab.com', 'TriCab Melbourne', 'error'], [$mailboxes[0]->address, $mailboxes[0]->displayName, $mailboxes[0]->connection]);
        self::assertSame('2026-10-18T09:30:00.000Z', $mailboxes[0]->lastSyncAt?->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_an_unknown_thread_is_null(): void
    {
        self::assertNull($this->store->thread('nope'));
    }

    public function test_assessing_a_message_that_was_never_recorded_is_refused(): void
    {
        $this->expectException(MailNotFound::class);
        $this->recorder->recordAssessment(MailFixtures::MAILBOX, '<ghost@x.example>', NewAssessment::classified('rfq', 0.9, 'x', 'm', 'v', MailFixtures::at()));
    }
}
