<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService;

/** GET /api/v1/finance/ap/vendors (source route 25): `{synced_at, items}`, the synced Odoo vendors (archived ones flagged) with how many live invoices each has. 503 `The Odoo vendor list is unavailable; try again shortly` when none is synced. Not scoped by mailbox. Gated on `FINANCE.AP.INVOICES.READ`. */
final class ListApVendorsAction
{
    use ApActionSupport;

    public function __construct(
        private readonly ApInvoiceVendorService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            return $this->ok($this->responder, $this->service->vendors());
        } catch (ApFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
