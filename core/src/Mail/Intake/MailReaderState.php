<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use DateTimeImmutable;

/**
 * What the reader remembers between passes, outside the database (so an idle pass never needs it): the
 * delta cursor, the mailbox's last recorded connection state, and the messages recorded but not yet
 * assessed.
 */
interface MailReaderState
{
    /** The link to resume from: a `nextLink` mid-sync, or the last `deltaLink`. Null before the first pass. */
    public function cursor(): ?string;

    /** Saved atomically: a reader that dies mid-write leaves the old cursor. */
    public function saveCursor(string $link, bool $complete): void;

    public function clearCursor(): void;

    /** @return array{connection: string, at: DateTimeImmutable}|null */
    public function connection(): ?array;

    public function saveConnection(string $connection, DateTimeImmutable $at): void;

    /** A message recorded whose assessment may not have been. Survives a pass that died between the two. */
    public function isPending(string $key): bool;

    public function markPending(string $key): void;

    public function clearPending(string $key): void;
}
