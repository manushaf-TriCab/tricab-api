<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use TriCab\Core\Mail\Intake\MailFolder;
use TriCab\Core\Mail\Intake\MailReaderState;

final class MemoryReaderState implements MailReaderState
{
    public ?string $cursor = null;

    public bool $complete = false;

    /** @var array<string, string> folder id => link, for every folder but the Inbox */
    public array $folderCursors = [];

    /** @var array{folders: list<MailFolder>, at: DateTimeImmutable}|null */
    public ?array $folderList = null;

    /** @var array{connection: string, at: DateTimeImmutable}|null */
    public ?array $connection = null;

    /** @var array<string, true> */
    public array $pending = [];

    public int $cursorWrites = 0;

    public function cursor(string $folder = MailFolder::INBOX): ?string
    {
        return $folder === MailFolder::INBOX ? $this->cursor : ($this->folderCursors[$folder] ?? null);
    }

    public function saveCursor(string $link, bool $complete, string $folder = MailFolder::INBOX): void
    {
        if ($folder === MailFolder::INBOX) {
            $this->cursor = $link;
            $this->complete = $complete;
        } else {
            $this->folderCursors[$folder] = $link;
        }
        ++$this->cursorWrites;
    }

    public function clearCursor(string $folder = MailFolder::INBOX): void
    {
        if ($folder === MailFolder::INBOX) {
            $this->cursor = null;
        } else {
            unset($this->folderCursors[$folder]);
        }
    }

    public function folders(): ?array
    {
        return $this->folderList;
    }

    public function saveFolders(array $folders, DateTimeImmutable $at): void
    {
        $this->folderList = ['folders' => $folders, 'at' => $at];
    }

    public function clearFolders(): void
    {
        $this->folderList = null;
    }

    public function connection(): ?array
    {
        return $this->connection;
    }

    public function saveConnection(string $connection, DateTimeImmutable $at): void
    {
        $this->connection = ['connection' => $connection, 'at' => $at];
    }

    public function isPending(string $key): bool
    {
        return isset($this->pending[$key]);
    }

    public function markPending(string $key): void
    {
        $this->pending[$key] = true;
    }

    public function clearPending(string $key): void
    {
        unset($this->pending[$key]);
    }

    /** @var array<string, array{threadId: string, messageId: string, label: string, injection: bool, attempts: int, nextAt: int}> */
    public array $retries = [];

    public function draftRetries(): array
    {
        return $this->retries;
    }

    public function saveDraftRetry(string $key, array $entry): void
    {
        $this->retries[$key] = $entry;
    }

    public function clearDraftRetry(string $key): void
    {
        unset($this->retries[$key]);
    }
}
