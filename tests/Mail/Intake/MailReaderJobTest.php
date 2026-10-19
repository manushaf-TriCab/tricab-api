<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Mail\Intake\FileMailReaderLock;
use TriCab\Api\Mail\Intake\MailReaderJob;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailReaderOptions;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/** The exit codes the WebJob and the runbook promise. */
final class MailReaderJobTest extends TestCase
{
    private string $dir;

    private ScriptedDeltaSource $source;

    private MemoryReaderState $state;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $out;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $err;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rfq-mail-job-' . bin2hex(random_bytes(4));
        $this->out = new \ArrayObject();
        $this->err = new \ArrayObject();
        $this->source = new ScriptedDeltaSource();
        $this->state = new MemoryReaderState();
        $this->state->saveCursor('delta-old', true);
        $this->state->cursorWrites = 0;
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/pass.lock');
        @rmdir($this->dir);
    }

    private function job(string $mode = 'shadow', bool $read = true, ?MailStore $store = null): MailReaderJob
    {
        $store ??= new InMemoryMailStore();
        $graph = new InMemoryMailboxReader();
        $graph->add(Mails::MAILBOX, Mails::body('a'));
        $pass = new MailReaderPass(new MailReaderOptions(Mails::MAILBOX, $read, $mode), $this->source, $graph, new MailIntakeRecorder($store), $store, new ScriptedClassifier(), $this->state, [], null, fn () => new DateTimeImmutable('2026-10-18 10:00:00 UTC'));

        return new MailReaderJob($pass, $this->dir, function (string $l): void {
            $this->out->append($l);
        }, function (string $l): void {
            $this->err->append($l);
        });
    }

    public function test_exit_0_for_a_pass_and_one_summary_line_only_when_something_happened(): void
    {
        $this->state->saveConnection('connected', new DateTimeImmutable('2026-10-18 09:59:00 UTC'));
        self::assertSame(MailReaderJob::EXIT_OK, $this->job()->run());
        self::assertCount(0, $this->out, 'an idle pass prints nothing');

        $this->source->pages = [new MailChanges([Mails::mail('a')], [], 'delta-new', true)];
        self::assertSame(MailReaderJob::EXIT_OK, $this->job()->run());
        self::assertCount(1, $this->out);
        $line = json_decode((string) $this->out[0], true);
        self::assertIsArray($line);
        self::assertSame(1, $line['recorded']);
    }

    public function test_exit_3_when_paused_or_off(): void
    {
        self::assertSame(MailReaderJob::EXIT_PAUSED, $this->job('off')->run());
        self::assertSame(MailReaderJob::EXIT_PAUSED, $this->job('shadow', false)->run());
    }

    public function test_exit_75_when_the_database_is_resuming_and_the_cursor_stays_where_it_was(): void
    {
        $store = $this->createMock(MailStore::class);
        $store->method('ensureMailbox')->willThrowException(new DatabaseTemporarilyUnavailable());
        $this->source->pages = [new MailChanges([Mails::mail('a')], [], 'delta-new', true)];

        self::assertSame(MailReaderJob::EXIT_DATABASE_RESUMING, $this->job('shadow', true, $store)->run());

        self::assertSame('delta-old', $this->state->cursor, 'nothing is lost: the next pass is handed the same messages');
        self::assertSame(0, $this->state->cursorWrites);
        self::assertStringContainsString('database is not available yet', (string) $this->err[0]);
    }

    public function test_exit_4_when_graph_refuses_and_the_cursor_stays(): void
    {
        $this->source->pages = [new MailboxReadUnavailable('Microsoft Graph could not be reached.')];

        self::assertSame(MailReaderJob::EXIT_GRAPH, $this->job()->run());
        self::assertSame('delta-old', $this->state->cursor);
        self::assertStringContainsString('could not be reached', (string) $this->err[0]);
    }

    public function test_exit_1_for_anything_unexpected(): void
    {
        $this->source->pages = [new \LogicException('boom')];

        self::assertSame(MailReaderJob::EXIT_FAILED, $this->job()->run());
        self::assertSame('delta-old', $this->state->cursor);
    }

    public function test_exit_5_when_another_pass_holds_the_lock_and_nothing_is_read(): void
    {
        $held = FileMailReaderLock::acquire($this->dir);
        self::assertNotNull($held);

        self::assertSame(MailReaderJob::EXIT_LOCKED, $this->job()->run());
        self::assertSame([], $this->source->calls);

        $held->release();
        self::assertSame(MailReaderJob::EXIT_OK, $this->job()->run());
    }

    public function test_the_lock_is_released_after_a_failure(): void
    {
        $this->source->pages = [new \LogicException('boom')];
        $this->job()->run();

        $lock = FileMailReaderLock::acquire($this->dir);
        self::assertNotNull($lock);
        $lock->release();
    }
}
