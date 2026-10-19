<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** `MailThreadPage`. */
final readonly class ThreadPage
{
    /** @param list<ThreadSummary> $items */
    public function __construct(public array $items, public ?string $nextCursor, public int $total)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (ThreadSummary $t): array => $t->toArray(), $this->items),
            'nextCursor' => $this->nextCursor,
            'total' => $this->total,
        ];
    }
}
