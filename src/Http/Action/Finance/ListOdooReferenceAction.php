<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecord;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncService;

/**
 * GET /api/v1/finance/reference/{list}: a synced Odoo list (`companies`, `vendors`,
 * `cost-centres`, `accounts`), as the portal's reference route served it.
 *
 * By default live, active records. `?include_inactive=1` adds archived ones. With
 * `?updated_since=<ISO time>` every record synced since, archived and deleted included
 * (`deleted: true`), so a cache refreshes without refetching the list; the answer's
 * `server_time` is the cursor for the next call. Sorted by name, then Odoo id. Item keys
 * are the portal's (`odoo_id`, `active`, `deleted`, `write_date`, `synced_at` and the
 * payload's own), so the apps port without a rename.
 *
 * Gated on `FINANCE.AP2.ACCESS` (Tri-AP): the one app that may read these lists today
 * (Phase 1 table). Vendors carry email, VAT and phone, so this is not open to every
 * finance user; another app that needs a list gets its own permission or route.
 */
final class ListOdooReferenceAction
{
    public function __construct(
        private readonly OdooSyncService $sync,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        $list = $args['list'] ?? '';
        if (!in_array($list, OdooLists::KEYS, true)) {
            return $this->responder->error('FINANCE_NOT_FOUND', 'Unknown reference list.', 404);
        }
        $query = $request->getQueryParams();

        $since = null;
        $sinceRaw = trim(is_string($query['updated_since'] ?? null) ? $query['updated_since'] : '');
        if ($sinceRaw !== '') {
            try {
                $since = (new DateTimeImmutable(str_replace('Z', '+00:00', $sinceRaw)))->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable) {
                return $this->responder->error('FINANCE_INPUT_INVALID', 'updated_since must be an ISO date-time.', 422);
            }
        }
        $includeInactive = in_array(strtolower(is_string($query['include_inactive'] ?? null) ? $query['include_inactive'] : ''), ['1', 'true', 'yes', 'on'], true);

        $items = $this->sync->list($list, $includeInactive, $since);

        return $this->responder->success([
            'list' => $list,
            'items' => array_map(static fn (OdooRecord $record): array => $record->toArray(), $items),
            'synced_at' => $this->sync->lastSuccess()?->finishedAt?->format('c'),
            // A sync stamps its rows with its start time but commits at the end, so the cursor
            // for the caller's next updated_since never passes the start of one still running.
            'server_time' => $this->sync->readCursor()->format('c'),
        ]);
    }
}
