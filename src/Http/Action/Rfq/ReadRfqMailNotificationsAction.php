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

/** POST /api/v1/rfqs/mail-notifications/read: marks the signed-in person's notices read. Gated on `RFQ.DRAFT.CREATE`. */
final class ReadRfqMailNotificationsAction
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
        if ($this->settings->rfqMailNotifyEnabled) {
            $this->store->markAllRead($user->userId);
        }

        return $this->responder->success(['read' => true]);
    }
}
