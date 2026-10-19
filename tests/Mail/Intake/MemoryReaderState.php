<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use TriCab\Core\Mail\Intake\MailReaderState;

final class MemoryReaderState implements MailReaderState
{
    public ?string $cursor = null;

    public bool $complete = false;

    /** @var array{connection: string, at: DateTimeImmutable}|null */
    public ?array $connection = null;

    /** @var array<string, true> */
    public array $pending = [];

    public int $cursorWrites = 0;

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function saveCursor(string $link, bool $complete): void
    {
        $this->cursor = $link;
        $this->complete = $complete;
        ++$this->cursorWrites;
    }

    public function clearCursor(): void
    {
        $this->cursor = null;
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
}
