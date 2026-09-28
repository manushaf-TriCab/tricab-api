<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One saved revision of an RFQ's header and lines. Contract `rfq.revision.v1`.
 *
 * Revisions are immutable and numbered from 1. A save names the revision it
 * was based on and is refused with 409 if that is no longer the latest
 * (invariant 7) — the source's `expected_revision`. A sync attempt binds to
 * exactly one revision number, and so does a pricing snapshot.
 *
 * `actorUserId` is null only for revisions the system wrote (a reader
 * applying rows to an untouched draft); `cause` says which.
 */
final class ReviewRevision
{
    public const CAUSE_CREATED = 'created';
    public const CAUSE_SAVED = 'saved';
    public const CAUSE_READER_APPLIED = 'reader_applied';
    public const CAUSE_PROPOSAL_ACCEPTED = 'proposal_accepted';

    /**
     * @param list<ParsedLine> $lines
     */
    public function __construct(
        public readonly int $revision,
        public readonly ?int $basedOnRevision,
        public readonly string $cause,
        public readonly ?int $actorUserId,
        public readonly DateTimeImmutable $savedAtUtc,
        public readonly QuoteHeader $header,
        public readonly array $lines,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'revision' => $this->revision,
            'basedOnRevision' => $this->basedOnRevision,
            'cause' => $this->cause,
            'savedAt' => $this->savedAtUtc->format(DateTimeInterface::ATOM),
            'header' => $this->header->toArray(),
            'lines' => array_map(static fn (ParsedLine $l): array => $l->toArray(), $this->lines),
        ];
    }
}
