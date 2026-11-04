<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Intake\IncomingMail;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailFolder;
use TriCab\Core\Mail\Intake\MailReaderOptions;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxSyncExpired;

/** The reader over the Inbox AND its child folders: discovery, a cursor per folder, moves between folders. */
final class MailReaderSubfoldersTest extends TestCase
{
    private ScriptedDeltaSource $source;

    private InMemoryMailboxReader $graph;

    private InMemoryMailStore $store;

    private ScriptedClassifier $classifier;

    private MemoryReaderState $state;

    private DateTimeImmutable $now;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->source = new ScriptedDeltaSource();
        $this->source->folderList = [new MailFolder('f-guy', 'NZ PROJECTS - GUY', 'Inbox/NZ PROJECTS - GUY'), new MailFolder('f-bob', 'Bob', 'Inbox/Bob')];
        $this->graph = new InMemoryMailboxReader();
        $this->store = new InMemoryMailStore();
        $this->classifier = new ScriptedClassifier();
        $this->state = new MemoryReaderState();
        $this->now = new DateTimeImmutable('2026-10-18 10:00:00 UTC');
    }

    /** @param list<string> $exclude */
    private function pass(int $maxPages = 40, int $passSeconds = 100, int $refreshMinutes = 30, array $exclude = []): MailReaderPass
    {
        return new MailReaderPass(
            new MailReaderOptions(Mails::MAILBOX, true, 'shadow', 14, $passSeconds, 360, $maxPages, folderRefreshMinutes: $refreshMinutes, excludeFolders: $exclude),
            $this->source,
            $this->graph,
            new MailIntakeRecorder($this->store),
            $this->store,
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
     * @param list<IncomingMail> $mails
     * @param list<string> $removed
     */
    private function page(array $mails, string $link, array $removed = []): MailChanges
    {
        foreach ($mails as $mail) {
            $this->graph->add(Mails::MAILBOX, Mails::body(substr($mail->graphMessageId, 6)));
        }

        return new MailChanges($mails, $removed, $link, true);
    }

    private static function relocated(IncomingMail $m, string $newGraphId): IncomingMail
    {
        return new IncomingMail($newGraphId, $m->internetMessageId, $m->conversationId, $m->subject, $m->from, $m->to, $m->cc, $m->receivedAt, $m->bodyPreview, $m->isRead, $m->hasAttachments, $m->odataType, $m->headers, $m->attachments);
    }

    private function threadCount(): int
    {
        return $this->store->listThreads(new ThreadQuery('all'))->total;
    }

    public function test_every_folder_in_the_subtree_is_read_with_its_own_window_start_and_cursor(): void
    {
        $this->source->folderPages['f-guy'] = [$this->page([Mails::mail('g1')], 'delta-guy')];
        $this->source->folderPages['f-bob'] = [$this->page([Mails::mail('b1')], 'delta-bob')];
        $this->source->pages = [$this->page([Mails::mail('i1')], 'delta-inbox')];

        $result = $this->pass()->run();

        self::assertSame(['inbox', 'f-guy', 'f-bob'], $this->source->folderCalls);
        self::assertSame(3, $result->folders);
        self::assertSame(3, $result->recorded);
        self::assertSame('delta-inbox', $this->state->cursor());
        self::assertSame('delta-guy', $this->state->cursor('f-guy'));
        self::assertSame('delta-bob', $this->state->cursor('f-bob'));
        foreach ($this->source->calls as $call) {
            self::assertNull($call[0], 'each folder starts from the window on its first run');
            self::assertSame('2026-10-04T10:00:00+00:00', $call[1]->format('c'), 'RFQ_MAIL_FIRST_RUN_DAYS back');
        }

        $this->source->calls = [];
        $this->pass()->run();
        self::assertSame(['delta-inbox', 'delta-guy', 'delta-bob'], array_column($this->source->calls, 0), 'each resumes from its own link');
    }

    public function test_the_folder_list_is_cached_then_refreshed_when_stale(): void
    {
        $this->pass()->run();
        $this->now = $this->now->modify('+29 minutes');
        $this->pass()->run();
        self::assertSame(1, $this->source->folderListings, 'trusted for 30 minutes');

        $this->now = $this->now->modify('+2 minutes');
        $this->pass()->run();
        self::assertSame(2, $this->source->folderListings);
    }

    public function test_a_new_folder_is_picked_up_at_refresh_and_starts_from_the_window(): void
    {
        $this->pass()->run();
        $this->source->folderList[] = new MailFolder('f-new', 'New', 'Inbox/New');
        $this->now = $this->now->modify('+31 minutes');
        $this->source->calls = [];
        $this->source->folderCalls = [];

        $this->pass()->run();

        self::assertSame([null], $this->source->linksFor('f-new'), 'read once, from the window');
    }

    public function test_a_folder_that_404s_loses_its_cursor_and_the_list_is_rebuilt_next_pass_while_the_others_carry_on(): void
    {
        $this->pass()->run();
        $this->source->folderPages['f-guy'] = [new MailboxItemNotFound('gone')];
        $this->source->folderList = [new MailFolder('f-bob', 'Bob', 'Inbox/Bob')];
        $this->source->folderCalls = [];

        $this->pass()->run();

        self::assertNull($this->state->cursor('f-guy'));
        self::assertNull($this->state->folderList, 'forgotten so the next pass lists the folders again');
        self::assertContains('f-bob', $this->source->folderCalls);

        $this->source->folderCalls = [];
        $this->pass()->run();
        self::assertSame(['inbox', 'f-bob'], $this->source->folderCalls);
    }

    public function test_the_inbox_404_is_not_swallowed(): void
    {
        $this->source->pages = [new MailboxItemNotFound('no mailbox')];
        $this->expectException(MailboxItemNotFound::class);
        $this->pass()->run();
    }

    public function test_a_410_in_one_folder_resyncs_only_that_folder(): void
    {
        $this->pass()->run();
        $this->source->folderPages['f-guy'] = [new MailboxSyncExpired('410'), new MailChanges([], [], 'delta-guy-2', true)];
        $this->source->calls = [];
        $this->source->folderCalls = [];

        $result = $this->pass()->run();

        self::assertTrue($result->resynced);
        self::assertSame('delta-guy-2', $this->state->cursor('f-guy'));
        self::assertSame('delta-empty', $this->state->cursor(), 'the Inbox cursor was left alone');
    }

    public function test_a_message_filed_from_the_inbox_into_a_folder_is_recorded_and_classified_once(): void
    {
        $inbox = Mails::mail('a');
        $this->source->pages = [$this->page([$inbox], 'inbox-1')];
        $this->pass()->run();
        self::assertSame(1, $this->threadCount());
        self::assertCount(1, $this->classifier->inputs);

        // Moved: the Inbox delta says removed; the folder's delta delivers it as a new row (a different Graph id here, as
        // happens without immutable ids; the internetMessageId is the same).
        $moved = self::relocated($inbox, 'graph-a-moved');
        $this->source->pages = [new MailChanges([], ['graph-a'], 'inbox-2', true)];
        $this->source->folderPages['f-guy'] = [$this->page([$moved], 'guy-1')];
        $headerFetches = $this->source->headerFetches;
        $readsBefore = $this->graph->reads;
        $this->now = $this->now->modify('+1 minute');

        $result = $this->pass()->run();

        self::assertSame(1, $result->received);
        self::assertSame(1, $result->duplicates);
        self::assertSame(0, $result->recorded);
        self::assertSame(1, $result->removed);
        self::assertSame(1, $this->threadCount(), 'still one thread');
        self::assertCount(1, $this->classifier->inputs, 'not classified a second time');
        self::assertSame($readsBefore, $this->graph->reads, 'no body, attachment or header fetch for a message already recorded');
        self::assertSame($headerFetches, $this->source->headerFetches);
        self::assertSame([], $this->state->pending);
    }

    public function test_a_move_seen_in_the_folder_before_the_inbox_removal_gives_the_same_single_record(): void
    {
        $inbox = Mails::mail('a');
        $moved = self::relocated($inbox, 'graph-a-moved');
        // The folder is read after the Inbox, so seed the order the other way: the folder delivers it first (no
        // Inbox row ever arrived, e.g. it was filed within the same minute), then the Inbox only reports a removal.
        $this->source->folderPages['f-guy'] = [$this->page([$moved], 'guy-1')];
        $this->source->pages = [new MailChanges([], ['graph-a'], 'inbox-1', true)];

        $this->pass()->run();

        self::assertSame(1, $this->threadCount());
        self::assertCount(1, $this->classifier->inputs);
    }

    public function test_a_removal_never_deletes_or_hides_what_was_recorded(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')], 'inbox-1')];
        $this->pass()->run();
        $before = $this->store->listThreads(new ThreadQuery('all'));

        $this->source->pages = [new MailChanges([], ['graph-a'], 'inbox-2', true)];
        $result = $this->pass()->run();

        self::assertSame(1, $result->removed);
        $after = $this->store->listThreads(new ThreadQuery('all'));
        self::assertSame($before->total, $after->total);
        self::assertSame($before->items[0]->id, $after->items[0]->id);
        self::assertSame(0, $this->store->listThreads(new ThreadQuery('ignored'))->total);
    }

    public function test_a_message_that_is_in_a_folder_and_was_already_seen_in_the_inbox_by_its_message_id_not_its_graph_id(): void
    {
        $this->source->pages = [$this->page([Mails::mail('a')], 'inbox-1')];
        $this->source->folderPages['f-guy'] = [$this->page([self::relocated(Mails::mail('a'), 'something-else-entirely')], 'guy-1')];

        $result = $this->pass()->run();

        self::assertSame(1, $result->recorded);
        self::assertSame(1, $result->duplicates);
        self::assertCount(1, $this->classifier->inputs);
    }

    public function test_the_time_and_page_budget_is_shared_and_the_inbox_always_gets_a_turn(): void
    {
        $this->source->pages = [new MailChanges([], [], 'inbox-next', false)];

        $this->pass(maxPages: 1)->run();

        self::assertSame(['inbox'], $this->source->folderCalls, 'one page in total, spent on the Inbox');
        self::assertSame('inbox-next', $this->state->cursor());
        self::assertNull($this->state->cursor('f-guy'));

        // Next pass: the Inbox finishes (one empty page) and the page budget stops the others.
        $this->source->folderCalls = [];
        $this->pass(maxPages: 2)->run();
        self::assertSame(['inbox', 'f-guy'], $this->source->folderCalls);
    }

    public function test_folders_can_be_excluded_by_name_with_everything_below_them(): void
    {
        $this->source->folderList[] = new MailFolder('f-sub', 'Old', 'Inbox/Bob/Old');

        $this->pass(exclude: ['bob'])->run();

        self::assertSame(['inbox', 'f-guy'], $this->source->folderCalls);
    }

    public function test_only_the_inbox_subtree_is_ever_read(): void
    {
        $this->pass()->run();
        foreach ($this->source->folderCalls as $id) {
            self::assertContains($id, ['inbox', 'f-guy', 'f-bob']);
        }
    }

    public function test_a_failed_folder_listing_still_reads_the_inbox_and_the_last_known_folders(): void
    {
        $this->pass()->run();
        $this->now = $this->now->modify('+31 minutes');
        $this->source->folderListFailure = new MailboxReadFailed('Graph hiccup');
        $this->source->folderCalls = [];

        $this->pass()->run();

        self::assertSame(['inbox', 'f-guy', 'f-bob'], $this->source->folderCalls, 'last known list');
        self::assertStringContainsString('Could not list the Inbox folders', implode("\n", $this->log));

        // With nothing known yet it is the Inbox alone.
        $this->state->folderList = null;
        $this->source->folderCalls = [];
        $this->pass()->run();
        self::assertSame(['inbox'], $this->source->folderCalls);
    }

    public function test_an_idle_pass_over_many_folders_still_leaves_the_database_alone(): void
    {
        $this->state->saveConnection('connected', $this->now->modify('-10 minutes'));
        $store = $this->createMock(\TriCab\Core\Mail\Contract\MailStore::class);
        $store->expects(self::never())->method(self::anything());
        $pass = new MailReaderPass(new MailReaderOptions(Mails::MAILBOX, true, 'shadow'), $this->source, $this->graph, new MailIntakeRecorder($store), $store, $this->classifier, $this->state, [], null, fn (): DateTimeImmutable => $this->now);

        $result = $pass->run();

        self::assertFalse($result->touchedDatabase);
        self::assertSame(3, $result->folders);
    }
}
