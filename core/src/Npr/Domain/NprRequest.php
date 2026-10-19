<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

use DateTimeImmutable;

/**
 * One NPR as stored: the header row plus its current revision's answers.
 *
 * `answers` is the request-scoped answer envelopes keyed by field id;
 * `variants` is the Section 5 product variants, each `{id, label, answers}`.
 * `version` is the row's optimistic-concurrency token (`ROWVERSION` as hex);
 * it changes on every write to the header, so a save based on a stale read
 * is refused even though the revision number did not move.
 */
final readonly class NprRequest
{
    /**
     * @param array<string, mixed>       $answers
     * @param list<array<string, mixed>> $variants
     */
    public function __construct(
        public string $publicId,
        public string $requestNumber,
        public int $revision,
        public RequestStatus $status,
        public string $title,
        public string $customerName,
        public string $urgencyLevel,
        public int $requesterUserId,
        public string $requesterEmail,
        public string $requesterName,
        public string $requesterBranch,
        public array $answers,
        public array $variants,
        public string $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $submittedAt = null,
        public ?string $screeningOutcome = null,
        public ?DateTimeImmutable $tsdReceivedAt = null,
    ) {
    }

    /**
     * In the TSD queue: with TSD or later, or returned to Sales after TSD had
     * received it (docs/ACCESS_AND_ROUTING.md: TSD sees endorsed receipts,
     * including returned cases). A manager-stage return never reached TSD.
     */
    public function isVisibleToTsd(): bool
    {
        return $this->status->isVisibleToTsd()
            || ($this->status === RequestStatus::ReturnedToSales && $this->tsdReceivedAt !== null);
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->requesterUserId === $userId;
    }
}
