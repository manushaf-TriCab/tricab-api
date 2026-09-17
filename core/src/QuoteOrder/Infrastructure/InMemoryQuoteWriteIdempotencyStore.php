<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;

/**
 * In-memory store for Phase 4C idempotency tests. Not production storage.
 */
final class InMemoryQuoteWriteIdempotencyStore implements QuoteWriteIdempotencyStore
{
    /** @var array<string, QuoteWriteIdempotencyRecord> */
    private array $records = [];

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?QuoteWriteIdempotencyRecord {
        return $this->records[$this->key($actorKey, $operation, $idempotencyKey)] ?? null;
    }

    public function record(QuoteWriteIdempotencyRecord $record): void
    {
        $this->records[$this->key($record->actorKey, $record->operation, $record->idempotencyKey)] = $record;
    }

    private function key(string $actorKey, string $operation, string $idempotencyKey): string
    {
        return $actorKey . "\0" . $operation . "\0" . $idempotencyKey;
    }
}
