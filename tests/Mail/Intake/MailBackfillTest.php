<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Core\Mail\Application\DTO\ThreadQuery;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Intake\ClassifierFailed;
use TriCab\Core\Mail\Intake\IncomingMail;
use TriCab\Core\Mail\Intake\MailBackfill;
use TriCab\Core\Mail\Intake\MailBackfillSource;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailFolder;
use TriCab\Core\Mail\Intake\MailReaderOptions;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Mail\Intake\PacedMailClassifier;
use TriCab\Core\Mail\Intake\TransientMailReaderState;

/** The backfill end to end over fakes: a scripted folder lister, the in-memory mail store, a scripted classifier. */
final class MailBackfillTest extends TestCase
{
    private InMemoryMailStore $store;

    private InMemoryMailboxReader $graph;

    private ScriptedClassifier $inner;

    private PacedMailClassifier $paced;

    private ScriptedDeltaSource $deltaSource;

    private BackfillSourceFake $source;

    /** @var list<float> */
    private array $sleeps = [];

    /** @var list<string> */
    private array $out = [];

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->store = new InMemoryMailStore();
        $this->graph = new InMemoryMailboxReader();
        $this->inner = new ScriptedClassifier();
        $this->paced = new PacedMailClassifier($this->inner, 0.5, 3, 2.0, function (float $s): void {
            $this->sleeps[] = $s;
        });
        $this->deltaSource = new ScriptedDeltaSource();
        $this->source = new BackfillSourceFake();
        $this->now = new DateTimeImmutable('2026-10-18 10:00:00 UTC');
    }

    /** @param list<IncomingMail> $mails */
    private function folder(string $id, string $path, array $mails): void
    {
        $this->source->folders[] = new MailFolder($id, basename($path), $path);
        $this->source->messages[$id] = $mails;
        foreach ($mails as $mail) {
            $this->graph->add(Mails::MAILBOX, Mails::body(substr($mail->graphMessageId, 6)));
        }
    }

    private function backfill(?PacedMailClassifier $classifier = null): MailBackfill
    {
        $pass = new MailReaderPass(
            new MailReaderOptions(Mails::MAILBOX, true, 'shadow'),
            $this->deltaSource,
            $this->graph,
            new MailIntakeRecorder($this->store),
            $this->store,
            $this->paced,
            new TransientMailReaderState(),
            [],
            null,
            fn (): DateTimeImmutable => $this->now,
        );

        return new MailBackfill(Mails::MAILBOX, $this->source, $pass, $classifier ?? $this->paced, function (string $line): void {
            $this->out[] = $line;
        });
    }

    private function threadCount(): int
    {
        return $this->store->listThreads(new ThreadQuery('all'))->total;
    }

    public function test_a_dry_run_counts_new_against_already_recorded_and_writes_nothing(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a'), Mails::mail('b')]);
        $this->folder('f-guy', 'Inbox/NZ PROJECTS - GUY', [Mails::mail('c'), Mails::mail('d'), Mails::mail('e')]);
        // 'a' and 'c' were recorded by the live reader already.
        $recorder = new MailIntakeRecorder($this->store);
        foreach (['a', 'c'] as $key) {
            $m = Mails::mail($key);
            $recorder->recordMessage(new \TriCab\Core\Mail\Application\DTO\NewMessage(Mails::MAILBOX, $m->graphMessageId, $m->internetMessageId, $m->conversationId, $m->subject, $m->from, $m->to, $m->cc, $m->receivedAt, $m->bodyPreview, false, []));
        }
        $threadsBefore = $this->threadCount();

        $summary = $this->backfill()->run($this->now, 14, false, false);

        self::assertSame([['Inbox', 2, 1, 1], ['Inbox/NZ PROJECTS - GUY', 3, 1, 2]], array_map(static fn (array $r): array => [$r['folder'], $r['seen'], $r['alreadyRecorded'], $r['new']], $summary));
        self::assertSame($threadsBefore, $this->threadCount(), 'nothing recorded');
        self::assertSame([], $this->inner->inputs, 'no model call');
        self::assertSame(0, $this->graph->reads, 'no body, attachment or header read either');
        self::assertSame([false], array_unique(array_map(static fn (array $r): bool => $r['recorded'] > 0, $summary)));
    }

    public function test_apply_records_and_classifies_the_new_ones_and_a_second_run_does_nothing(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a')]);
        $this->folder('f-guy', 'Inbox/Guy', [Mails::mail('b'), Mails::mail('own', from: Mails::MAILBOX), Mails::mail('c')]);

        $first = $this->backfill()->run($this->now, 14, false, true);

        self::assertSame(4, $this->threadCount());
        self::assertSame([1, 1], [$first[0]['recorded'], $first[0]['classified']]);
        self::assertSame([3, 2, 1], [$first[1]['recorded'], $first[1]['classified'], $first[1]['skipped']], 'the mailbox own mail is recorded as pre-filter skipped, not sent to the model');
        self::assertCount(3, $this->inner->inputs);

        $second = $this->backfill()->run($this->now, 14, false, true);

        self::assertSame([4, 0], [array_sum(array_column($second, 'alreadyRecorded')), array_sum(array_column($second, 'recorded'))]);
        self::assertCount(3, $this->inner->inputs, 'not classified again');
        self::assertSame(4, $this->threadCount());
    }

    public function test_a_message_filed_in_a_folder_that_the_reader_recorded_from_the_inbox_is_not_recorded_again(): void
    {
        $this->folder('f-guy', 'Inbox/Guy', [Mails::mail('a')]);
        $this->backfill()->run($this->now, 14, false, true);
        // The same internetMessageId under another Graph id, in Deleted Items now.
        $moved = new IncomingMail('graph-a-moved', Mails::mail('a')->internetMessageId, 'conv-a', 'Quote', Mails::mail('a')->from, [], [], $this->now, 'x', false, false);
        $this->folder('deleteditems', 'Deleted Items', [$moved]);

        $summary = $this->backfill()->run($this->now, 14, true, true);

        self::assertSame(1, $this->threadCount());
        self::assertSame(1, $summary[1]['alreadyRecorded']);
        self::assertCount(1, $this->inner->inputs);
    }

    public function test_limit_stops_after_that_many_new_messages_and_a_later_run_carries_on(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a'), Mails::mail('b'), Mails::mail('c')]);

        $this->backfill()->run($this->now, 14, false, true, 2);
        self::assertSame(2, $this->threadCount());

        $this->backfill()->run($this->now, 14, false, true, 2);
        self::assertSame(3, $this->threadCount());
    }

    public function test_pages_are_followed(): void
    {
        $this->source->pageSize = 2;
        $this->folder('inbox', 'Inbox', [Mails::mail('a'), Mails::mail('b'), Mails::mail('c'), Mails::mail('d'), Mails::mail('e')]);

        $summary = $this->backfill()->run($this->now, 14, false, false);

        self::assertSame(5, $summary[0]['seen']);
        self::assertSame(3, $this->source->listCalls);
    }

    public function test_the_window_is_the_days_asked_and_never_more_than_thirty(): void
    {
        $this->folder('inbox', 'Inbox', []);

        $this->backfill()->run($this->now, 14, false, false);
        $this->backfill()->run($this->now, 90, false, false);

        self::assertSame('2026-10-04T10:00:00+00:00', $this->source->since[0]->format('c'));
        self::assertSame('2026-09-18T10:00:00+00:00', $this->source->since[1]->format('c'), 'clamped to 30 days');
    }

    public function test_a_429_backs_off_and_retries_then_records_the_real_verdict(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a')]);
        $this->inner->answers = [
            new ClassifierFailed('the model refused the request (HTTP 429)', 'm', 'v'),
            new ClassifierFailed('the model refused the request (HTTP 429)', 'm', 'v'),
        ];

        $summary = $this->backfill()->run($this->now, 14, false, true);

        self::assertSame([2.0, 4.0], $this->sleeps, 'exponential back-off, base 2 s here');
        self::assertSame(2, $this->paced->retries);
        self::assertSame(1, $summary[0]['classified']);
        self::assertSame(0, $summary[0]['errors']);
        self::assertSame(1, $this->store->listThreads(new ThreadQuery('rfq'))->total);
    }

    public function test_calls_are_paced_and_a_non_throttle_failure_is_not_retried(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a'), Mails::mail('b')]);
        $this->inner->answers = [new ClassifierFailed('output did not validate', 'm', 'v')];

        $summary = $this->backfill()->run($this->now, 14, false, true);

        self::assertSame([0.5], $this->sleeps, 'one pause between the two calls, no retry sleep');
        self::assertSame(1, $summary[0]['errors'], 'the failed one is recorded uncertain and counted');
        self::assertSame(1, $this->store->listThreads(new ThreadQuery('uncertain'))->total);
    }

    public function test_a_message_still_throttled_after_retries_is_deferred_not_recorded_and_the_run_stops_after_three(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a'), Mails::mail('b'), Mails::mail('c'), Mails::mail('d'), Mails::mail('e')]);
        $this->inner->throw = new ClassifierFailed('the model refused the request (HTTP 429)', 'm', 'v');

        $summary = $this->backfill()->run($this->now, 14, false, true);

        self::assertSame(0, $this->threadCount(), 'nothing recorded: no record without an assessment, no uncertain');
        self::assertSame(0, $this->store->listThreads(new ThreadQuery('uncertain'))->total);
        self::assertSame([3, 0, 0, 0], [$summary[0]['deferred'], $summary[0]['recorded'], $summary[0]['classified'], $summary[0]['errors']]);
        self::assertSame(3, $summary[0]['new'], 'the three tried are still new; the run stopped before the other two');
        self::assertStringContainsString('kept throttling', implode("\n", $this->out));
        self::assertStringContainsString('deferred   3', implode("\n", $this->out));

        // After the model recovers, a rerun picks every one of them up as new.
        $this->inner->throw = null;
        $second = $this->backfill()->run($this->now, 14, false, true);
        self::assertSame(5, $this->threadCount());
        self::assertSame([5, 0], [$second[0]['recorded'], $second[0]['deferred']]);
        self::assertSame(5, $this->store->listThreads(new ThreadQuery('rfq'))->total, 'all really classified');
    }

    public function test_a_throttle_that_clears_on_the_next_message_defers_only_the_one_and_later_ones_still_record(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a'), Mails::mail('b')]);
        // maxRetries 3: four throttles exhaust the first message; the second then succeeds.
        $this->inner->answers = array_fill(0, 4, new ClassifierFailed('the model refused the request (HTTP 429)', 'm', 'v'));

        $summary = $this->backfill()->run($this->now, 14, false, true);

        self::assertSame([1, 1], [$summary[0]['deferred'], $summary[0]['recorded']]);
        self::assertSame(1, $this->threadCount());
    }

    public function test_it_makes_no_draft_and_never_uses_the_delta_source(): void
    {
        $this->folder('inbox', 'Inbox', [Mails::mail('a')]);
        $this->backfill()->run($this->now, 14, false, true);

        // The pass is built with no draft automation and with transient state: no thread is drafted or linked.
        $thread = $this->store->listThreads(new ThreadQuery('all'))->items[0];
        self::assertSame([], $this->store->thread($thread->id)?->links);
        self::assertSame(0, $this->deltaSource->folderListings + count($this->deltaSource->calls), 'the delta source is not used for reading');
    }
}

/** A backfill source answering from arrays, with real paging. */
final class BackfillSourceFake implements MailBackfillSource
{
    /** @var list<MailFolder> */
    public array $folders = [];

    /** @var array<string, list<IncomingMail>> */
    public array $messages = [];

    public int $pageSize = 25;

    public int $listCalls = 0;

    /** @var list<DateTimeImmutable> */
    public array $since = [];

    public function backfillFolders(string $mailbox, bool $includeDeleted): array
    {
        return array_values(array_filter($this->folders, static fn (MailFolder $f): bool => $f->id !== 'deleteditems' || $includeDeleted));
    }

    public function listPage(string $mailbox, MailFolder $folder, ?string $link, DateTimeImmutable $since): MailChanges
    {
        ++$this->listCalls;
        if ($link === null) {
            $this->since[] = $since;
        }
        $offset = $link === null ? 0 : (int) $link;
        $rows = array_slice($this->messages[$folder->id] ?? [], $offset, $this->pageSize);
        $next = $offset + $this->pageSize;
        $more = $next < count($this->messages[$folder->id] ?? []);

        return new MailChanges($rows, [], $more ? (string) $next : '', !$more);
    }
}
