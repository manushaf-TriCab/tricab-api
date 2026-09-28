<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One entry in an RFQ's activity trail. Contract `rfq.audit-event.v1`.
 *
 * Mutations write their event in the same transaction as the revision they
 * describe (plan 2.4; source quirk Q13, where auditing was best-effort and
 * skipped while the store was damaged). Event types keep the source's
 * `rfq_draft_activity` names so migrated history reads as one trail.
 *
 * `detail` is structured, not prose, and never holds customer document
 * content, raw mail or secrets (invariant 11): before/after values of the
 * fields that changed, an attempt id, a reason a person typed.
 */
final class RfqAuditEvent
{
    public const CREATED = 'created';
    public const CLAIMED = 'claimed';
    public const ASSIGNED = 'assigned';
    public const CONTENT_UPDATED = 'content_updated';
    public const WORKFLOW_UPDATED = 'workflow_updated';
    public const COMMENTED = 'commented';
    public const ARCHIVED = 'archived';
    public const RESTORED = 'restored';
    public const READER_PROPOSAL = 'reader_proposal';
    public const READER_RESOLVED = 'reader_resolved';
    public const SYNC_QUEUED = 'sync_queued';
    public const SYNC_FINISHED = 'sync_finished';
    public const SYNC_RECONCILED = 'sync_reconciled';
    public const ACCESS_DENIED = 'access_denied';

    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public readonly string $publicId,
        public readonly string $type,
        public readonly ?int $actorUserId,
        public readonly ?string $actorDisplayName,
        public readonly ?int $revision,
        public readonly ?string $message,
        public readonly array $detail,
        public readonly DateTimeImmutable $occurredAtUtc,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'type' => $this->type,
            'actor' => $this->actorDisplayName,
            'system' => $this->actorUserId === null,
            'revision' => $this->revision,
            'message' => $this->message,
            'detail' => $this->detail,
            'occurredAt' => $this->occurredAtUtc->format(DateTimeInterface::ATOM),
        ];
    }
}
