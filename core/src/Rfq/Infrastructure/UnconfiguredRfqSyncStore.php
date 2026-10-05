<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqSyncStore;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Storage\DraftActor;

/** Where there is no Azure SQL: no attempt can be recorded, so none is made. */
final class UnconfiguredRfqSyncStore implements RfqSyncStore
{
    public function begin(string $draftId, int $expectedRevision, DraftActor $actor, array $rep, array $plan, string $payload, string $payloadSha256, string $correlationId, string $origin): ?string
    {
        throw new RfqStoreUnavailable();
    }

    public function noteHeaderIntent(string $attemptId): void
    {
        throw new RfqStoreUnavailable();
    }

    public function noteHeaderOk(string $attemptId, string $refno, string $refver): void
    {
        throw new RfqStoreUnavailable();
    }

    public function noteHeaderFailed(string $attemptId, string $error, bool $certain): void
    {
        throw new RfqStoreUnavailable();
    }

    public function noteLine(string $attemptId, int $index, bool $accepted, array $detail, int $nextLine): void
    {
        throw new RfqStoreUnavailable();
    }

    public function finishAttempt(string $attemptId, SyncAttemptState $state, array $result, string $error, string $reconcileNote, DraftActor $actor): void
    {
        throw new RfqStoreUnavailable();
    }

    public function attempt(string $attemptId): ?\TriCab\Core\Rfq\Application\DTO\SyncAttemptView
    {
        throw new RfqStoreUnavailable();
    }

    public function attemptsFor(string $draftId, int $limit): array
    {
        throw new RfqStoreUnavailable();
    }

    public function sweepStale(string $draftId, string $beforeIso): int
    {
        throw new RfqStoreUnavailable();
    }
}
