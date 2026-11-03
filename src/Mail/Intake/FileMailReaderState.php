<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Intake;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use TriCab\Api\Config\Settings;
use TriCab\Core\Mail\Intake\MailReaderState;

/**
 * The reader's memory as files under `var/rfq-mail/` (the directory the web app and the WebJob share, like
 * `var/rfq-reader/`). `state.json` holds the delta cursor and the last recorded connection state and is
 * replaced atomically (write a temp file, then rename), so a pass that dies mid-write leaves the old one.
 * `pending/<key>` marks a message recorded and not yet assessed. A deploy empties the directory: the next
 * pass then starts from `RFQ_MAIL_FIRST_RUN_DAYS` ago, which is safe because recording is idempotent.
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

    public function cursor(): ?string
    {
        $state = $this->read();
        $cursor = $state['cursor'] ?? null;
        if ($this->mailbox !== null && ($state['cursorMailbox'] ?? null) !== self::normalise($this->mailbox)) {
            return null;
        }

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function saveCursor(string $link, bool $complete): void
    {
        $state = $this->read();
        $state['cursor'] = $link;
        if ($this->mailbox !== null) {
            $state['cursorMailbox'] = self::normalise($this->mailbox);
        }
        $state['cursorComplete'] = $complete;
        $state['cursorSavedAt'] = gmdate('c');
        $this->write($state);
    }

    public function clearCursor(): void
    {
        $state = $this->read();
        unset($state['cursor'], $state['cursorMailbox'], $state['cursorComplete'], $state['cursorSavedAt']);
        $this->write($state);
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
