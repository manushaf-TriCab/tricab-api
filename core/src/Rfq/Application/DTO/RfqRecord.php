<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\IntakeChannel;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;

/**
 * One RFQ as a queue row: what a list or a header needs, no lines.
 *
 * `publicId` is the only identifier that leaves the server. The source's
 * draft id and the 4js quote number are separate fields, because a platform
 * id, a source id and a legacy quote identity are three different things
 * (plan, target architecture).
 *
 * `latestSyncState` is null when no sync was ever attempted. `readiness` is
 * not here: it is recomputed from the saved revision (invariant 6) and joins
 * the detail contract in Phase 4.
 */
final class RfqRecord
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $reference,
        public readonly string $title,
        public readonly ?string $customerCode,
        public readonly ?string $customerName,
        public readonly RfqOwnership $ownership,
        public readonly ?string $ownerDisplayName,
        public readonly ?string $assigneeDisplayName,
        public readonly WorkflowStatus $workflowStatus,
        public readonly Priority $priority,
        public readonly ?DateTimeImmutable $dueAtUtc,
        public readonly IntakeChannel $channel,
        public readonly ExtractionState $extractionState,
        public readonly ?SyncAttemptState $latestSyncState,
        public readonly ?string $quoteNumber,
        public readonly int $lineCount,
        public readonly int $revision,
        public readonly DateTimeImmutable $receivedAtUtc,
        public readonly DateTimeImmutable $updatedAtUtc,
    ) {
    }
}
