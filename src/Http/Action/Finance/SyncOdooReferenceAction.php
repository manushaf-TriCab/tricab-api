<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncBusy;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * POST /api/v1/finance/reference/sync: the portal's "sync now". Body `{"mode": "full" | "incremental"}`;
 * the mode is required, because the two do different things (only a full sweep can notice a
 * record deleted in Odoo). Gated on `FINANCE.ADMIN`.
 *
 * Answers 200 with the run when it succeeded; 502 `FINANCE_ODOO_SYNC_FAILED` with the run (and
 * why it failed) when it did not; 409 `FINANCE_ODOO_SYNC_BUSY` when one is already running;
 * 503 `FINANCE_ODOO_NOT_CONFIGURED` when `ODOO_URL`, `ODOO_API_KEY` or `ODOO_COMPANY_IDS` is
 * missing (nothing is recorded then); 422 for a bad mode. Each run an administrator starts is
 * written to `finance.audit_events`.
 */
final class SyncOdooReferenceAction
{
    public const ACTION_AUDIT = 'finance.odoo.sync';

    public function __construct(
        private readonly OdooSyncService $sync,
        private readonly Settings $settings,
        private readonly FinanceAuditLog $audit,
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
        $body = $request->getParsedBody();
        $mode = is_array($body) ? ($body['mode'] ?? null) : null;
        if ($mode !== OdooSyncRun::MODE_FULL && $mode !== OdooSyncRun::MODE_INCREMENTAL) {
            return $this->responder->error('FINANCE_INPUT_INVALID', 'mode must be "full" or "incremental".', 422);
        }
        if (!$this->settings->isOdooConfigured()) {
            return $this->responder->error('FINANCE_ODOO_NOT_CONFIGURED', 'Odoo is not configured (ODOO_URL, ODOO_API_KEY, ODOO_COMPANY_IDS).', 503);
        }

        try {
            $run = $this->sync->run($mode, OdooSyncRun::TRIGGER_ADMIN, $user->userId);
        } catch (OdooSyncBusy $busy) {
            return $this->responder->error('FINANCE_ODOO_SYNC_BUSY', $busy->getMessage(), 409);
        }

        $this->audit->record(new FinanceAuditEvent(
            self::ACTION_AUDIT,
            'odoo_sync_run',
            $run->id,
            ['mode' => $run->mode, 'status' => $run->status],
            $this->sync->now(),
            $user->userId,
        ));

        if ($run->status !== OdooSyncRun::STATUS_SUCCEEDED) {
            return $this->responder->payload([
                'success' => false,
                'error' => ['code' => 'FINANCE_ODOO_SYNC_FAILED', 'message' => $run->error ?? 'The Odoo sync failed.'],
                'data' => ['run' => $run->toArray()],
            ], 502);
        }

        return $this->responder->success(['run' => $run->toArray()]);
    }
}
