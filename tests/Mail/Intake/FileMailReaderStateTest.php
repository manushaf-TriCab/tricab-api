<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Intake\FileMailReaderLock;
use TriCab\Api\Mail\Intake\FileMailReaderState;
use TriCab\Core\Mail\Intake\MailFolder;

final class FileMailReaderStateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rfq-mail-state-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{*,pending/*}', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->dir . '/pending') && rmdir($this->dir . '/pending');
        is_dir($this->dir) && rmdir($this->dir);
    }

    public function test_nothing_is_remembered_at_first(): void
    {
        $state = new FileMailReaderState($this->dir);
        self::assertNull($state->cursor());
        self::assertNull($state->connection());
        self::assertFalse($state->isPending('abc123'));
    }

    public function test_a_cursor_is_only_used_for_the_mailbox_it_was_saved_for(): void
    {
        (new FileMailReaderState($this->dir, 'it.sales@tricab.com'))->saveCursor('https://graph/users/it.sales/delta?x', true);

        self::assertSame('https://graph/users/it.sales/delta?x', (new FileMailReaderState($this->dir, 'IT.Sales@tricab.com '))->cursor(), 'same mailbox, any case');
        self::assertNull((new FileMailReaderState($this->dir, 'nzprojects@tricab.com'))->cursor(), 'a new mailbox starts afresh');

        $next = new FileMailReaderState($this->dir, 'nzprojects@tricab.com');
        $next->saveCursor('https://graph/users/nzprojects/delta?y', false);
        self::assertSame('https://graph/users/nzprojects/delta?y', $next->cursor());
        self::assertNull((new FileMailReaderState($this->dir, 'it.sales@tricab.com'))->cursor(), 'switching back starts afresh too');
    }

    public function test_a_cursor_saved_without_a_mailbox_is_not_trusted_once_a_mailbox_is_known(): void
    {
        (new FileMailReaderState($this->dir))->saveCursor('https://graph/users/old/delta', true);

        self::assertNull((new FileMailReaderState($this->dir, 'nzprojects@tricab.com'))->cursor());
        self::assertSame('https://graph/users/old/delta', (new FileMailReaderState($this->dir))->cursor(), 'no mailbox given: unchanged behaviour');
    }

    public function test_the_cursor_and_connection_survive_a_new_instance_and_are_private(): void
    {
        $state = new FileMailReaderState($this->dir);
        $state->saveCursor('https://graph.microsoft.com/v1.0/delta?t=1', true);
        $state->saveConnection('connected', new DateTimeImmutable('2026-10-18 09:00:00 UTC'));

        $again = new FileMailReaderState($this->dir);
        self::assertSame('https://graph.microsoft.com/v1.0/delta?t=1', $again->cursor());
        self::assertSame('connected', $again->connection()['connection'] ?? null);
        self::assertSame('2026-10-18T09:00:00+00:00', $again->connection()['at']->format('c'));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/state.json')), -4));
        self::assertSame(['state.json'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])), 'no temp file is left behind');
    }

    public function test_saving_one_thing_keeps_the_other(): void
    {
        $state = new FileMailReaderState($this->dir);
        $state->saveCursor('c1', false);
        $state->saveConnection('paused', new DateTimeImmutable('2026-10-18 09:00:00 UTC'));
        $state->clearCursor();

        self::assertNull($state->cursor());
        self::assertSame('paused', $state->connection()['connection'] ?? null);
    }

    public function test_a_torn_state_file_is_a_lost_cursor_not_a_crash(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/state.json', '{"cursor": "abc');

        self::assertNull((new FileMailReaderState($this->dir))->cursor());
    }

    public function test_pending_markers_come_and_go(): void
    {
        $state = new FileMailReaderState($this->dir);
        $state->markPending('abc123');
        self::assertTrue($state->isPending('abc123'));
        $state->clearPending('abc123');
        self::assertFalse($state->isPending('abc123'));
        $state->clearPending('abc123');
    }

    public function test_only_one_pass_holds_the_lock_and_it_can_be_taken_again_after_release(): void
    {
        $first = FileMailReaderLock::acquire($this->dir);
        self::assertNotNull($first);
        self::assertNull(FileMailReaderLock::acquire($this->dir), 'a second pass is refused while the first runs');

        $first->release();
        $second = FileMailReaderLock::acquire($this->dir);
        self::assertNotNull($second);
        $second->release();
    }

    public function test_owed_drafts_survive_a_new_instance_and_clear_one_by_one(): void
    {
        $state = new FileMailReaderState($this->dir);
        self::assertSame([], $state->draftRetries());
        $a = ['threadId' => 't1', 'messageId' => 'm1', 'label' => 'rfq', 'injection' => false, 'attempts' => 0, 'nextAt' => 0];
        $b = ['threadId' => 't2', 'messageId' => 'm2', 'label' => 'rfq_followup', 'injection' => true, 'attempts' => 3, 'nextAt' => 1_800_000_000];
        $state->saveCursor('link', true);
        $state->saveDraftRetry('aaa', $a);
        $state->saveDraftRetry('bbb', $b);

        $again = new FileMailReaderState($this->dir);
        self::assertSame(['aaa' => $a, 'bbb' => $b], $again->draftRetries());
        self::assertSame('link', $again->cursor(), 'the cursor is untouched by retries');

        $again->clearDraftRetry('aaa');
        self::assertSame(['bbb' => $b], (new FileMailReaderState($this->dir))->draftRetries());
        $again->clearDraftRetry('bbb');
        $again->clearDraftRetry('never-there');
        self::assertSame([], (new FileMailReaderState($this->dir))->draftRetries());
        self::assertSame('link', (new FileMailReaderState($this->dir))->cursor());
    }

    public function test_an_old_single_cursor_file_loads_as_the_inbox_cursor_for_that_mailbox_only(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/state.json', json_encode(['cursor' => 'https://graph.microsoft.com/v1.0/old', 'cursorMailbox' => 'nzprojects@tricab.com', 'cursorComplete' => true, 'connection' => 'connected', 'connectionAt' => '2026-11-04T01:00:00+00:00']));

        $state = new FileMailReaderState($this->dir, 'NZProjects@tricab.com');
        self::assertSame('https://graph.microsoft.com/v1.0/old', $state->cursor());
        self::assertNull($state->cursor('f-guy'), 'a folder with no cursor starts from the window');
        self::assertNull((new FileMailReaderState($this->dir, 'it.sales@tricab.com'))->cursor(), '#181 rule: a cursor belongs to its mailbox');
        self::assertSame(1, $state->cursorCount());

        // Saving a folder cursor leaves the migrated Inbox one in place.
        $state->saveCursor('https://graph.microsoft.com/v1.0/guy', true, 'f-guy');
        self::assertSame('https://graph.microsoft.com/v1.0/old', $state->cursor());
        self::assertSame('https://graph.microsoft.com/v1.0/guy', $state->cursor('f-guy'));
        self::assertSame('connected', $state->connection()['connection'] ?? null);
    }

    public function test_each_folder_has_its_own_cursor_and_belongs_to_its_mailbox(): void
    {
        $state = new FileMailReaderState($this->dir, 'nzprojects@tricab.com');
        $state->saveCursor('inbox-link', true);
        $state->saveCursor('guy-link', false, 'AAMk/guy=');
        $state->saveCursor('bob-link', true, 'f-bob');

        $again = new FileMailReaderState($this->dir, 'nzprojects@tricab.com');
        self::assertSame('inbox-link', $again->cursor());
        self::assertSame('guy-link', $again->cursor('AAMk/guy='));
        self::assertSame('bob-link', $again->cursor('f-bob'));
        self::assertSame(3, $again->cursorCount());

        $other = new FileMailReaderState($this->dir, 'it.sales@tricab.com');
        self::assertNull($other->cursor());
        self::assertNull($other->cursor('f-bob'));

        $again->clearCursor('f-bob');
        self::assertNull($again->cursor('f-bob'));
        self::assertSame('guy-link', $again->cursor('AAMk/guy='));
        $again->clearCursor();
        self::assertNull($again->cursor());
        self::assertSame('guy-link', $again->cursor('AAMk/guy='));
    }

    public function test_the_inbox_cursor_is_still_written_under_the_old_keys_so_a_rollback_keeps_it(): void
    {
        (new FileMailReaderState($this->dir, 'nzprojects@tricab.com'))->saveCursor('inbox-link', true);

        $raw = json_decode((string) file_get_contents($this->dir . '/state.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($raw);
        self::assertSame('inbox-link', $raw['cursor']);
        self::assertSame('nzprojects@tricab.com', $raw['cursorMailbox']);
    }

    public function test_clear_all_cursors_forgets_every_folder_and_the_folder_list(): void
    {
        $state = new FileMailReaderState($this->dir, 'nzprojects@tricab.com');
        $state->saveCursor('i', true);
        $state->saveCursor('g', true, 'f-guy');
        $state->saveFolders([new MailFolder('f-guy', 'Guy', 'Inbox/Guy')], new DateTimeImmutable('2026-11-04 01:00:00 UTC'));
        $state->saveConnection('connected', new DateTimeImmutable('2026-11-04 01:00:00 UTC'));

        $state->clearAllCursors();

        self::assertSame(0, $state->cursorCount());
        self::assertNull($state->folders());
        self::assertSame('connected', $state->connection()['connection'] ?? null);
    }

    public function test_the_folder_list_survives_and_belongs_to_its_mailbox(): void
    {
        $state = new FileMailReaderState($this->dir, 'nzprojects@tricab.com');
        self::assertNull($state->folders());
        $state->saveFolders([new MailFolder('inbox', 'Inbox', 'Inbox'), new MailFolder('f-guy', 'NZ PROJECTS - GUY', 'Inbox/NZ PROJECTS - GUY')], new DateTimeImmutable('2026-11-04 01:00:00 UTC'));

        $found = (new FileMailReaderState($this->dir, 'NZPROJECTS@tricab.com'))->folders();
        self::assertNotNull($found);
        self::assertSame('2026-11-04T01:00:00+00:00', $found['at']->format('c'));
        self::assertSame(['inbox', 'f-guy'], array_map(static fn (MailFolder $f): string => $f->id, $found['folders']));
        self::assertSame('Inbox/NZ PROJECTS - GUY', $found['folders'][1]->path);
        self::assertNull((new FileMailReaderState($this->dir, 'it.sales@tricab.com'))->folders());

        $state->clearFolders();
        self::assertNull($state->folders());
    }
}
