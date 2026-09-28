<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\SyncHeaderState;

/**
 * One attempt to put one revision into 4js. Contract `rfq.sync-attempt.v1`.
 *
 * `payloadSha256` is the digest of the frozen plan — the lines as reviewed,
 * fixed at enqueue. A resume sends what was reviewed, not what the RFQ says
 * now (source `the_frozen_plan_carries_a_digest_of_itself`).
 *
 * `needsReconciliation` is derived from the state so the browser never has to
 * know which states mean "somebody must look in 4js".
 */
final class SyncAttemptView
{
    public function __construct(
        public readonly string $publicId,
        public readonly int $revision,
        public readonly SyncAttemptState $state,
        public readonly SyncHeaderState $headerState,
        public readonly int $attemptNo,
        public readonly ?string $quoteNumber,
        public readonly ?string $quoteVersion,
        public readonly int $linesTotal,
        public readonly int $linesSent,
        public readonly int $linesFailed,
        public readonly string $payloadSha256,
        public readonly ?string $error,
        public readonly ?string $reconcileNote,
        public readonly DateTimeImmutable $createdAtUtc,
        public readonly DateTimeImmutable $updatedAtUtc,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'revision' => $this->revision,
            'state' => $this->state->toString(),
            'headerState' => $this->headerState->toString(),
            'needsReconciliation' => $this->state->is(SyncAttemptState::UNCERTAIN),
            'attemptNo' => $this->attemptNo,
            'quoteNumber' => $this->quoteNumber,
            'quoteVersion' => $this->quoteVersion,
            'linesTotal' => $this->linesTotal,
            'linesSent' => $this->linesSent,
            'linesFailed' => $this->linesFailed,
            'payloadSha256' => $this->payloadSha256,
            'error' => $this->error,
            'reconcileNote' => $this->reconcileNote,
            'createdAt' => $this->createdAtUtc->format(DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAtUtc->format(DateTimeInterface::ATOM),
        ];
    }
}
