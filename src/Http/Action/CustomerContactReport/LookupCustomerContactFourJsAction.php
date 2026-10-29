<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\CustomerContactReportActor;
use TriCab\Api\Http\Auth\CustomerContactReportErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactFourJsLookup;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsDocument;
use TriCab\Core\CustomerContactReport\Domain\FourJs\FourJsUnavailable;

/**
 * GET /api/v1/customer-contacts/lookup/{kind}/{number}, kind order or invoice.
 * An order also returns its invoices. 4JS being down is 200 with status
 * "unavailable": the raiser can still save and type the amount by hand.
 */
final class LookupCustomerContactFourJsAction
{
    public function __construct(
        private readonly CustomerContactFourJsLookup $fourJs,
        private readonly JsonResponder $responder,
        private readonly CustomerContactReportErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $actor = CustomerContactReportActor::fromRequest($request);
            CustomerContactReportActor::assertAccess($actor);
            $kind = strtolower(RouteParams::require($request, 'kind'));
            $number = RouteParams::require($request, 'number');
            if (!in_array($kind, ['order', 'invoice'], true)) {
                return $this->responder->error('NOT_FOUND', 'Unknown lookup.', 404);
            }
            if (CustomerContactFourJsLookup::reference($number) === '') {
                return $this->responder->success(['status' => 'invalid', 'document' => null, 'invoices' => []]);
            }
            try {
                if ($kind === 'order') {
                    $document = $this->fourJs->order($number);
                    $invoices = $document === null ? [] : $this->fourJs->orderInvoices($number);
                } else {
                    $document = $this->fourJs->invoice($number);
                    $invoices = [];
                }
            } catch (FourJsUnavailable) {
                return $this->responder->success(['status' => 'unavailable', 'document' => null, 'invoices' => []]);
            }

            return $this->responder->success([
                'status' => $document === null ? 'not_found' : 'found',
                'document' => $document === null ? null : self::document($document),
                'invoices' => array_map(self::document(...), $invoices),
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(FourJsDocument $d): array
    {
        return [
            'type' => $d->type,
            'orderNo' => $d->orderNo,
            'invoiceNo' => $d->invoiceNo,
            'customerCode' => $d->customerCode,
            'customerName' => $d->customerName,
            'contact' => $d->contact,
            'customerPo' => $d->customerPo,
            'currency' => $d->currency,
            'gstRate' => $d->gstRate,
            'total' => $d->total,
            'lines' => array_map(static fn ($l): array => [
                'orderNo' => $l->orderNo,
                'invoiceNo' => $l->invoiceNo,
                'lineNo' => $l->lineNo,
                'productCode' => $l->productCode,
                'description' => $l->description,
                'qty' => $l->qty,
                'unit' => $l->unit,
                'packs' => $l->packs,
                'packSize' => $l->packSize,
                'unitPrice' => $l->unitPrice,
                'net' => $l->net,
                'warehouse' => $l->warehouse,
                'comment' => $l->comment,
            ], $d->lines),
        ];
    }
}
