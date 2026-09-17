<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\QuoteOrder;

use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemPackDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchResult;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSummary;

final class QuoteOrderDtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function searchResult(QuoteOrderSearchResult $result): array
    {
        return [
            'items' => array_map(self::summary(...), $result->items),
            'page' => $result->page,
            'pageSize' => $result->pageSize,
            'total' => $result->total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(QuoteOrderSummary $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'quoteId' => $item->quoteId,
            'orderId' => $item->orderId,
            'date' => $item->date,
            'raisedBy' => $item->raisedBy,
            'representative' => $item->representative,
            'customerCode' => $item->customerCode,
            'customerName' => $item->customerName,
            'reference' => $item->reference,
            'status' => $item->status,
            'statusCode' => $item->statusCode,
            'statusLabel' => $item->statusLabel,
            'total' => $item->total,
            'totalStatus' => $item->totalStatus,
            'currency' => $item->currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function quote(QuoteDetails $details): array
    {
        return [
            'id' => $details->id,
            'type' => $details->type,
            'quoteId' => $details->quoteId,
            'orderId' => $details->orderId,
            'date' => $details->date,
            'validDate' => $details->validDate,
            'raisedBy' => $details->raisedBy,
            'representative' => $details->representative,
            'customerCode' => $details->customerCode,
            'customerName' => $details->customerName,
            'contactName' => $details->contactName,
            'reference' => $details->reference,
            'status' => $details->status,
            'statusCode' => $details->statusCode,
            'statusLabel' => $details->statusLabel,
            'total' => $details->total,
            'totalStatus' => $details->totalStatus,
            'currency' => $details->currency,
            'items' => array_map(self::item(...), $details->items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(OrderDetails $details): array
    {
        return [
            'id' => $details->id,
            'orderId' => $details->orderId,
            'customerPoNumber' => $details->customerPoNumber,
            'reference' => $details->reference,
            'issueDate' => $details->issueDate,
            'comments' => $details->comments,
            'quoteId' => $details->quoteId,
            'quote' => $details->quote !== null ? self::quote($details->quote) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(QuoteItemDetails $item): array
    {
        return [
            'id' => $item->id,
            'itemNo' => $item->itemNo,
            'productCode' => $item->productCode,
            'quantity' => $item->quantity,
            'packCount' => $item->packCount,
            'unitPrice' => $item->unitPrice,
            'reference' => $item->reference,
            'packs' => array_map(self::pack(...), $item->packs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pack(QuoteItemPackDetails $pack): array
    {
        return [
            'id' => $pack->id,
            'unitsPerPack' => $pack->unitsPerPack,
            'packCount' => $pack->packCount,
            'comments' => $pack->comments,
        ];
    }
}
