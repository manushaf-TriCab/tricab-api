<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Mail\Notify\RfqMailNotificationStore;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * GET /api/v1/rfqs/mail-notifications: the signed-in person's "new email RFQ" notices. Gated on `RFQ.DRAFT.CREATE`.
 * With `RFQ_MAIL_NOTIFY_ENABLED` off the list is empty and no table is touched, so the web can always ask.
 */
final class ListRfqMailNotificationsAction
{
    public function __construct(
        private readonly RfqMailNotificationStore $store,
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        if (!$this->settings->rfqMailNotifyEnabled) {
            return $this->responder->success(['unread' => 0, 'items' => []]);
        }
        $items = $this->store->forUser($user->userId);

        return $this->responder->success([
            'unread' => count(array_filter($items, static fn ($n): bool => !$n->read)),
            'items' => array_map(static fn ($n): array => $n->toArray(), $items),
        ]);
    }

}
