<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;

/**
 * Azure-side idempotency record for quotes.persistDraft.
 */
final readonly class QuoteWriteIdempotencyRecord
{
    public function __construct(
        public string $actorKey,
        public string $operation,
        public string $idempotencyKey,
        public string $requestHash,
        public string $state,
        public string $platformOperationId,
        public ?string $quoteId,
        public ?string $cartPublicId,
        public ?string $reconciliationFingerprint,
        public DateTimeImmutable $createdAtUtc,
        public DateTimeImmutable $updatedAtUtc,
        public ?string $failureReason = null,
    ) {
    }

    public function stateVo(): QuoteWriteIdempotencyState
    {
        return QuoteWriteIdempotencyState::fromString($this->state);
    }
}
