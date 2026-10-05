<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeInterface;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;

/**
 * `GET /api/v1/rfqs` item — contract `rfq.summary.v1`.
 *
 * Field names are camelCase, like the rest of `/api/v1`. The web scaffold's
 * snake_case `RfqItem` is a draft this replaces; `tricab-web/src/api/admin/rfqs.ts`
 * parses exactly this shape.
 *
 * People are named, never addressed: there is no email field. The scaffold
 * carried `assignee_email`, and `localStorage` kept it — invariant 11.
 */
final class RfqSummary
{
    /**
     * @param list<string> $allowedActions
     */
    public function __construct(
        public readonly RfqRecord $record,
        public readonly array $allowedActions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $r = $this->record;

        return [
            'publicId' => $r->publicId,
            'reference' => $r->reference,
            'title' => $r->title,
            'customer' => $r->customerCode === null && $r->customerName === null
                ? null
                : ['code' => $r->customerCode, 'name' => $r->customerName],
            'branchCode' => $r->ownership->branchCode,
            'owner' => $r->ownerDisplayName,
            'assignee' => $r->assigneeDisplayName,
            'workflowStatus' => $r->workflowStatus->toString(),
            'priority' => $r->priority->toString(),
            'blockedReason' => $r->workflowStatus->is(WorkflowStatus::BLOCKED) ? $r->blockedReason : null,
            'dueAt' => $r->dueAtUtc?->format(DateTimeInterface::ATOM),
            'archived' => $r->ownership->archived,
            'channel' => $r->channel->toString(),
            'extractionState' => $r->extractionState->toString(),
            'latestSyncState' => $r->latestSyncState?->toString(),
            'quoteNumber' => $r->quoteNumber,
            'lineCount' => $r->lineCount,
            'revision' => $r->revision,
            'receivedAt' => $r->receivedAtUtc->format(DateTimeInterface::ATOM),
            'updatedAt' => $r->updatedAtUtc->format(DateTimeInterface::ATOM),
            'allowedActions' => $this->allowedActions,
        ];
    }
}
