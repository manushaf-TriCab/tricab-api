<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/**
 * The transactional seam: turns a workflow event into outbox rows, inside the
 * transition's transaction. Sends nothing. With mail off the row is written as
 * `suppressed` (kept for the record, never flooded out when mail is switched
 * on later; an admin can retry it).
 */
final class OutboxNprEventSink implements NprEventSink
{
    public function __construct(
        private readonly NprNotificationPlanner $planner,
        private readonly NprMailOutbox $outbox,
        private readonly NprMailMode $mode,
    ) {
    }

    public function record(NprWorkflowEvent $event, NprRequest $saved, int $activityId, int $requestId): void
    {
        $plan = $this->planner->plan($event, $saved);
        if ($plan->isEmpty()) {
            return;
        }
        $status = match (true) {
            $this->mode === NprMailMode::Off => NewNprOutboxEntry::STATUS_SUPPRESSED,
            $plan->to === [] && $plan->cc === [] => NewNprOutboxEntry::STATUS_FAILED,
            default => NewNprOutboxEntry::STATUS_PENDING,
        };

        $this->outbox->enqueue(new NewNprOutboxEntry(
            $event->name,
            $saved->publicId,
            $plan->to,
            $plan->cc,
            $plan->missing,
            [
                'event' => $event->name,
                'requestNumber' => $saved->requestNumber,
                'publicId' => $saved->publicId,
                'title' => $saved->title,
                'customer' => $saved->customerName,
                'urgency' => $saved->urgencyLevel,
                'revision' => $saved->revision,
                'actor' => $event->actor->name,
                'summary' => $event->summary,
                'toStatus' => $event->toStatus->value,
                'outcome' => $saved->screeningOutcome,
                'tsdOwner' => $saved->tsdOwner?->name,
                'tsdOwnerEmail' => $saved->tsdOwner?->email,
            ],
            $status,
        ), $activityId, $requestId);
    }
}
