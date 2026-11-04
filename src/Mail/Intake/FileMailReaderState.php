<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Intake;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use TriCab\Api\Config\Settings;
use TriCab\Core\Mail\Intake\MailFolder;
use TriCab\Core\Mail\Intake\MailReaderState;

/**
 * The reader's memory as files under `var/rfq-mail/` (the directory the web app and the WebJob share, like
 * `var/rfq-reader/`). `state.json` holds the delta cursor and the last recorded connection state and is
 * replaced atomically (write a temp file, then rename), so a pass that dies mid-write leaves the old one.
 * `pending/<key>` marks a message recorded and not yet assessed. A deploy empties the directory: the next
 * pass then starts from `RFQ_MAIL_FIRST_RUN_DAYS` ago, which is safe because recording is idempotent.
 *
 * **One cursor per folder.** The reader reads the Inbox and the folders under it, each with its own delta cursor
 * in `cursors` (keyed by folder id; the Inbox is `inbox`). A state file from before folders has one top-level
 * `cursor`/`cursorMailbox`: that is read as the Inbox's cursor, and the Inbox's is still written there too.
 *
 * **The cursor belongs to one mailbox.** A Graph delta link is a full URL naming the mailbox it was issued
 * for, so after `RFQ_MAIL_MAILBOX` changes the old link would keep reading the old mailbox. The cursor is
 * saved with its mailbox and ignored for any other one (or when it was saved without one): the new
 * mailbox starts from `RFQ_MAIL_FIRST_RUN_DAYS` ago, like a first run.
 */
final class FileMailReaderState implements MailReaderState
{
    private const STATE = 'state.json';

    public function __construct(private readonly string $directory, private readonly ?string $mailbox = null)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $path = trim($settings->rfqMailStatePath);
        if (!str_starts_with($path, '/') && !(strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':')) {
            $path = dirname(__DIR__, 3) . '/' . ltrim($path, '/\\');
        }

        return new self(rtrim($path, '/\\'), $settings->rfqMailMailbox);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function cursor(string $folder = MailFolder::INBOX): ?string
    {
        $state = $this->read();
        $entry = $this->cursorEntry($state, $folder);
        if ($entry === null) {
            return null;
        }
        // A cursor belongs to the mailbox it was issued for (and one saved without a mailbox is not trusted once a mailbox is known).
        if ($this->mailbox !== null && $entry['mailbox'] !== self::normalise($this->mailbox)) {
            return null;
        }

        return $entry['link'];
    }

    public function saveCursor(string $link, bool $complete, string $folder = MailFolder::INBOX): void
    {
        $state = $this->read();
        $cursors = is_array($state['cursors'] ?? null) ? $state['cursors'] : [];
        $saved = [
            'link' => $link,
            'mailbox' => $this->mailbox !== null ? self::normalise($this->mailbox) : null,
            'complete' => $complete,
            'savedAt' => gmdate('c'),
        ];
        $cursors[$folder] = $saved;
        $state['cursors'] = $cursors;
        if ($folder === MailFolder::INBOX) {
            // The Inbox cursor is also kept under the pre-folders keys, so a file written now still loads if this
            // release is rolled back to one that reads a single cursor.
            $state['cursor'] = $link;
            if ($this->mailbox !== null) {
                $state['cursorMailbox'] = self::normalise($this->mailbox);
            } else {
                unset($state['cursorMailbox']);
            }
            $state['cursorComplete'] = $complete;
            $state['cursorSavedAt'] = $saved['savedAt'];
        }
        $this->write($state);
    }

    public function clearCursor(string $folder = MailFolder::INBOX): void
    {
        $state = $this->read();
        if (is_array($state['cursors'] ?? null)) {
            unset($state['cursors'][$folder]);
            if ($state['cursors'] === []) {
                unset($state['cursors']);
            }
        }
        if ($folder === MailFolder::INBOX) {
            unset($state['cursor'], $state['cursorMailbox'], $state['cursorComplete'], $state['cursorSavedAt']);
        }
        $this->write($state);
    }

    /** Forget every folder's cursor and the folder list (`--reset-cursor`): the next pass starts each folder from the window. */
    public function clearAllCursors(): void
    {
        $state = $this->read();
        unset($state['cursors'], $state['folders'], $state['cursor'], $state['cursorMailbox'], $state['cursorComplete'], $state['cursorSavedAt']);
        $this->write($state);
    }

    /** How many folders have a saved cursor for this mailbox (the Inbox counts, old single cursor included). */
    public function cursorCount(): int
    {
        $state = $this->read();
        $keys = array_keys(is_array($state['cursors'] ?? null) ? $state['cursors'] : []);
        $keys[] = MailFolder::INBOX;
        $count = 0;
        foreach (array_unique($keys) as $key) {
            $count += $this->cursor((string) $key) !== null ? 1 : 0;
        }

        return $count;
    }

    public function folders(): ?array
    {
        $stored = $this->read()['folders'] ?? null;
        $at = is_array($stored) ? ($stored['at'] ?? null) : null;
        $mailbox = is_array($stored) ? ($stored['mailbox'] ?? null) : null;
        if (!is_array($stored) || !is_string($at) || !is_array($stored['list'] ?? null)) {
            return null;
        }
        if ($this->mailbox !== null && $mailbox !== self::normalise($this->mailbox)) {
            return null;
        }
        $folders = [];
        foreach ($stored['list'] as $f) {
            if (is_array($f) && is_string($f['id'] ?? null) && $f['id'] !== '') {
                $folders[] = new MailFolder($f['id'], is_string($f['name'] ?? null) ? $f['name'] : '', is_string($f['path'] ?? null) ? $f['path'] : '');
            }
        }
        try {
            return ['folders' => $folders, 'at' => new DateTimeImmutable($at, new DateTimeZone('UTC'))];
        } catch (\Exception) {
            return null;
        }
    }

    public function saveFolders(array $folders, DateTimeImmutable $at): void
    {
        $state = $this->read();
        $state['folders'] = [
            'at' => $at->setTimezone(new DateTimeZone('UTC'))->format('c'),
            'mailbox' => $this->mailbox !== null ? self::normalise($this->mailbox) : null,
            'list' => array_map(static fn (MailFolder $f): array => ['id' => $f->id, 'name' => $f->name, 'path' => $f->path], $folders),
        ];
        $this->write($state);
    }

    public function clearFolders(): void
    {
        $state = $this->read();
        if (isset($state['folders'])) {
            unset($state['folders']);
            $this->write($state);
        }
    }

    /**
     * The saved cursor for a folder: the `cursors` map first; for the Inbox, the single cursor older releases
     * wrote (`cursor` + `cursorMailbox`) when the map has no entry yet.
     *
     * @param array<string, mixed> $state
     * @return array{link: string, mailbox: ?string}|null
     */
    private function cursorEntry(array $state, string $folder): ?array
    {
        $entry = is_array($state['cursors'] ?? null) ? ($state['cursors'][$folder] ?? null) : null;
        if (is_array($entry) && is_string($entry['link'] ?? null) && $entry['link'] !== '') {
            return ['link' => $entry['link'], 'mailbox' => is_string($entry['mailbox'] ?? null) ? $entry['mailbox'] : null];
        }
        if ($folder === MailFolder::INBOX && is_string($state['cursor'] ?? null) && $state['cursor'] !== '') {
            return ['link' => $state['cursor'], 'mailbox' => is_string($state['cursorMailbox'] ?? null) ? $state['cursorMailbox'] : null];
        }

        return null;
    }

    public function connection(): ?array
    {
        $state = $this->read();
        $connection = $state['connection'] ?? null;
        $at = $state['connectionAt'] ?? null;
        if (!is_string($connection) || !is_string($at)) {
            return null;
        }
        try {
            return ['connection' => $connection, 'at' => new DateTimeImmutable($at, new DateTimeZone('UTC'))];
        } catch (\Exception) {
            return null;
        }
    }

    public function saveConnection(string $connection, DateTimeImmutable $at): void
    {
        $state = $this->read();
        $state['connection'] = $connection;
        $state['connectionAt'] = $at->setTimezone(new DateTimeZone('UTC'))->format('c');
        $this->write($state);
    }

    public function isPending(string $key): bool
    {
        return is_file($this->pendingPath($key));
    }

    public function markPending(string $key): void
    {
        $this->ensureDirectory($this->directory . '/pending');
        file_put_contents($this->pendingPath($key), gmdate('c'));
    }

    public function clearPending(string $key): void
    {
        $path = $this->pendingPath($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function draftRetries(): array
    {
        $out = [];
        $stored = $this->read()['draftRetries'] ?? [];
        foreach (is_array($stored) ? $stored : [] as $key => $e) {
            if (!is_string($key) || !is_array($e)) {
                continue;
            }
            $out[$key] = [
                'threadId' => is_string($e['threadId'] ?? null) ? $e['threadId'] : '',
                'messageId' => is_string($e['messageId'] ?? null) ? $e['messageId'] : '',
                'label' => is_string($e['label'] ?? null) ? $e['label'] : '',
                'injection' => ($e['injection'] ?? false) === true,
                'attempts' => is_int($e['attempts'] ?? null) ? $e['attempts'] : 0,
                'nextAt' => is_int($e['nextAt'] ?? null) ? $e['nextAt'] : 0,
            ];
        }

        return $out;
    }

    public function saveDraftRetry(string $key, array $entry): void
    {
        $state = $this->read();
        $retries = is_array($state['draftRetries'] ?? null) ? $state['draftRetries'] : [];
        $retries[$key] = $entry;
        $state['draftRetries'] = $retries;
        $this->write($state);
    }

    public function clearDraftRetry(string $key): void
    {
        $state = $this->read();
        if (!is_array($state['draftRetries'] ?? null) || !isset($state['draftRetries'][$key])) {
            return;
        }
        unset($state['draftRetries'][$key]);
        if ($state['draftRetries'] === []) {
            unset($state['draftRetries']);
        }
        $this->write($state);
    }

    private static function normalise(string $mailbox): string
    {
        return strtolower(trim($mailbox));
    }

    private function pendingPath(string $key): string
    {
        return $this->directory . '/pending/' . preg_replace('/[^a-f0-9]/', '', strtolower($key));
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->directory . '/' . self::STATE;
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A torn or hand-edited file is a lost cursor, not a crash: the next pass resyncs.
            return [];
        }

        return is_array($decoded) ? array_filter($decoded, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $this->ensureDirectory($this->directory);
        $temp = tempnam($this->directory, 'state-');
        if ($temp === false) {
            throw new RuntimeException('The RFQ mail state directory is not writable.');
        }
        try {
            if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
                throw new RuntimeException('The RFQ mail state could not be written.');
            }
            chmod($temp, 0600);
            if (!rename($temp, $this->directory . '/' . self::STATE)) {
                throw new RuntimeException('The RFQ mail state could not be replaced.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('The RFQ mail state directory could not be created.');
        }
    }
}
