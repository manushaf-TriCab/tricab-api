<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use DateTimeImmutable;

/**
 * Reader state that lives only for this process: the backfill runs the pass's intake logic with it so it can
 * never read or move the live reader's cursors, folder list or retry list.
 */
final class TransientMailReaderState implements MailReaderState
{
    /** @var array<string, true> */
    private array $pending = [];

    public function cursor(string $folder = MailFolder::INBOX): ?string
    {
        return null;
    }

    public function saveCursor(string $link, bool $complete, string $folder = MailFolder::INBOX): void
    {
    }

    public function clearCursor(string $folder = MailFolder::INBOX): void
    {
    }

    public function folders(): ?array
    {
        return null;
    }

    public function saveFolders(array $folders, DateTimeImmutable $at): void
    {
    }

    public function clearFolders(): void
    {
    }

    public function connection(): ?array
    {
        return null;
    }

    public function saveConnection(string $connection, DateTimeImmutable $at): void
    {
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

    public function draftRetries(): array
    {
        return [];
    }

    public function saveDraftRetry(string $key, array $entry): void
    {
    }

    public function clearDraftRetry(string $key): void
    {
    }
}
