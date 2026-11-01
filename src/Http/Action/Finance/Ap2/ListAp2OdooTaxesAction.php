<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;

/**
 * GET /odoo/taxes: the synced purchase taxes, for the settings tax map. `FINANCE.AP2.SETTINGS.MANAGE`.
 * Empty until the Odoo sync has copied them (it runs only while AP2 is on).
 */
final class ListAp2OdooTaxesAction
{
    use Ap2ActionSupport;

    public function __construct(
        private readonly OdooRecordStore $records,
        private readonly Ap2ActorResolver $actors,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        if ($this->actors->actorOf($request) === null) {
            return $this->unauthenticated($this->responder);
        }
        $taxes = [];
        foreach ($this->records->list(OdooLists::TAXES, false, null) as $record) {
            $p = $record->payload;
            if (($p['type_tax_use'] ?? null) !== 'purchase') {
                continue;
            }
            $taxes[] = [
                'id' => $record->odooId,
                'name' => is_string($p['name'] ?? null) ? $p['name'] : '',
                'description' => is_string($p['description'] ?? null) ? $p['description'] : '',
                'amount' => is_numeric($p['amount'] ?? null) ? (float) $p['amount'] : 0.0,
                'amountType' => is_string($p['amount_type'] ?? null) ? $p['amount_type'] : '',
                'companyId' => is_numeric($p['company_id'] ?? null) ? (int) $p['company_id'] : null,
                'companyName' => is_string($p['company_name'] ?? null) ? $p['company_name'] : '',
            ];
        }

        return $this->ok($this->responder, $taxes, 200);
    }
}
