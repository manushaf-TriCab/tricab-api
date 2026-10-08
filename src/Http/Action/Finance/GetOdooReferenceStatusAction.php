<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncService;

/**
 * GET /api/v1/finance/reference/status: what an administrator checks, as the portal's
 * Odoo status page showed it: whether Odoo is configured, how many live records each list
 * holds, the last success, the ten most recent runs, and the API key's expiry with a flag
 * once it is within 30 days (an expired key makes every sync fail). Never the key itself.
 * Gated on `FINANCE.ADMIN`.
 */
final class GetOdooReferenceStatusAction
{
    public function __construct(
        private readonly OdooSyncService $sync,
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        return $this->responder->success(['configured' => $this->settings->isOdooConfigured()] + $this->sync->status());
    }
}
