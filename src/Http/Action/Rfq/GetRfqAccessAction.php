<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqSyncService;

/**
 * GET /api/v1/rfqs/me — the signed-in person's effective RFQ access.
 *
 * The SPA builds the module's navigation from this and from each record's
 * `allowedActions`, instead of deriving a role from role names in the browser
 * (the scaffold's `useRfqMe`). Gated on `RFQ.ACCESS`; returns no RFQ.
 *
 * `sync` says whether sending a quote to 4js can run at all on this server
 * (`{available, reason, code}`), so the editor draws Sync to 4js disabled with
 * the reason instead of discovering a 503 (parity plan E7.3).
 */
final class GetRfqAccessAction
{
    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly JsonResponder $responder,
        private readonly RfqSyncService $sync,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        return $this->responder->success($this->actors->accessSummary($user)->toArray() + ['sync' => $this->sync->availability()]);
    }
}
