<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Npr\Mail\NprMailDispatcher;
use TriCab\Core\Npr\Mail\NprMailOutbox;
use TriCab\Core\Npr\Mail\NprOutboxEntry;

/**
 * Delivery status of the workflow email, for administrators (`NPR.ADMIN`):
 *
 *  - `list`  GET  /npr/admin/notifications?status=&limit=: the outbox, newest first, with each
 *            row's recipients and any required recipient that had no address
 *            (`hasMissingRecipients`; those deliveries read `(missing: role)` and stay failed);
 *  - `retry` POST /npr/admin/notifications/{id}/retry: a failed or suppressed row goes back to
 *            pending and is sent now (unless mail is off). Never repeats the workflow step.
 */
final class NprNotificationAction
{
    use NprActionSupport;

    private const STATUSES = ['pending', 'sent', 'failed', 'suppressed'];

    public function __construct(
        private readonly NprMailOutbox $outbox,
        private readonly NprMailDispatcher $dispatcher,
        private readonly JsonResponder $responder,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        if (($args['step'] ?? '') === 'retry') {
            $id = ctype_digit($args['id'] ?? '') ? (int) $args['id'] : 0;
            $entry = $id > 0 ? $this->dispatcher->retry($id) : null;

            return $entry === null
                ? $this->responder->error('NPR_NOT_FOUND', 'Only a failed or suppressed notification can be retried.', 404)
                : $this->responder->success(['mode' => $this->dispatcher->mode()->value] + self::entry($entry));
        }

        $query = $request->getQueryParams();
        $status = is_string($query['status'] ?? null) && in_array($query['status'], self::STATUSES, true) ? $query['status'] : null;
        $limit = is_string($query['limit'] ?? null) && ctype_digit($query['limit']) ? (int) $query['limit'] : 50;

        return $this->responder->success([
            'mode' => $this->dispatcher->mode()->value,
            'outstanding' => $this->outbox->outstanding(),
            'items' => array_map(self::entry(...), $this->outbox->recent(max(1, min(200, $limit)), $status)),
        ]);
    }

    /** @return array<string, mixed> */
    private static function entry(NprOutboxEntry $entry): array
    {
        $time = static fn (?\DateTimeImmutable $at): ?string => $at?->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $entry->id,
            'requestPublicId' => $entry->requestPublicId,
            'requestNumber' => $entry->requestNumber,
            'event' => $entry->event,
            'status' => $entry->status,
            'attempts' => $entry->attempts,
            'lastAttemptAt' => $time($entry->lastAttemptAt),
            'error' => $entry->error,
            'createdAt' => $time($entry->createdAt),
            'to' => $entry->to,
            'cc' => $entry->cc,
            'hasMissingRecipients' => $entry->hasMissingRecipients(),
            'deliveries' => array_map(static fn ($d): array => [
                'id' => $d->id,
                'recipient' => $d->email,
                'type' => $d->type,
                'status' => $d->status,
                'attempts' => $d->attempts,
                'sentAt' => $time($d->sentAt),
                'error' => $d->error,
            ], $entry->deliveries),
        ];
    }
}
