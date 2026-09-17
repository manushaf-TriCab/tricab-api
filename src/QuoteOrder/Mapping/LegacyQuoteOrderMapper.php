<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Mapping;

use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteItemPackDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSummary;
use TriCab\Core\QuoteOrder\Domain\ValueObject\MoneyTotal;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteStatus;

/**
 * Maps legacy MySQL rows to public DTOs. Legacy column names stay here only.
 */
final class LegacyQuoteOrderMapper
{
    private readonly LegacyCyrteMappingContract $currency;

    public function __construct(?LegacyCyrteMappingContract $currency = null)
    {
        $this->currency = $currency ?? new LegacyCyrteMappingContract();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mapSummary(array $row): QuoteOrderSummary
    {
        $quoteId = $this->string($row['quote_id'] ?? $row['id'] ?? '');
        $orderId = $this->nullableString($row['order_id'] ?? $row['po_id'] ?? null);
        $isOrder = $orderId !== null && $orderId !== '';
        $type = $isOrder ? QuoteOrderType::ORDER : QuoteOrderType::QUOTE;
        $status = QuoteStatus::fromCode($row['status_code'] ?? $row['st'] ?? null);
        $money = MoneyTotal::fromLegacyNet($row['total_net'] ?? $row['tnet'] ?? null);
        $raisedBy = $this->resolveRaisedBy($row);

        return new QuoteOrderSummary(
            id: $isOrder ? (string) $orderId : $quoteId,
            type: $type,
            quoteId: $quoteId,
            orderId: $isOrder ? $orderId : null,
            date: $this->nullableString($row['quote_date'] ?? $row['de'] ?? $row['date_sort'] ?? null),
            raisedBy: $raisedBy,
            representative: $this->nullableString($row['representative'] ?? $row['rep_name'] ?? null),
            customerCode: $this->nullableString($row['customer_code'] ?? $row['code'] ?? null),
            customerName: $this->nullableString($row['customer_name'] ?? $row['name'] ?? null),
            reference: $this->resolveReference($row, $isOrder),
            status: $status->status,
            statusCode: $status->statusCode,
            statusLabel: $status->statusLabel,
            total: $money->amount,
            totalStatus: $money->status,
            currency: $this->currencyCode($row['currency_id'] ?? $row['cy'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param list<QuoteItemDetails> $items
     */
    public function mapQuoteDetails(array $header, array $items): QuoteDetails
    {
        $summary = $this->mapSummary($header);

        return new QuoteDetails(
            id: $summary->quoteId,
            type: QuoteOrderType::QUOTE,
            quoteId: $summary->quoteId,
            orderId: $summary->orderId,
            date: $summary->date,
            validDate: $this->nullableString($header['valid_date'] ?? $header['dvalid'] ?? null),
            raisedBy: $summary->raisedBy,
            representative: $summary->representative,
            customerCode: $summary->customerCode,
            customerName: $summary->customerName,
            contactName: $this->nullableString($header['contact_name'] ?? null),
            reference: $summary->reference,
            status: $summary->status,
            statusCode: $summary->statusCode,
            statusLabel: $summary->statusLabel,
            total: $summary->total,
            totalStatus: $summary->totalStatus,
            currency: $summary->currency,
            items: $items,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<QuoteItemPackDetails> $packs
     */
    public function mapItem(array $row, array $packs = []): QuoteItemDetails
    {
        return new QuoteItemDetails(
            id: $this->string($row['item_id'] ?? $row['id'] ?? ''),
            itemNo: $this->nullableString($row['item_no'] ?? $row['itm'] ?? null),
            productCode: $this->nullableString($row['product_code'] ?? $row['cata'] ?? null),
            quantity: $this->nullableString($row['quantity'] ?? $row['qotqty'] ?? null),
            packCount: $this->nullableString($row['pack_count'] ?? $row['qty'] ?? null),
            unitPrice: $this->nullableString($row['unit_price'] ?? $row['nprc'] ?? null),
            reference: $this->nullableString($row['item_reference'] ?? $row['ref'] ?? null),
            packs: $packs,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mapPack(array $row): QuoteItemPackDetails
    {
        return new QuoteItemPackDetails(
            id: $this->string($row['id'] ?? ''),
            unitsPerPack: $this->nullableString($row['pk'] ?? null),
            packCount: $this->nullableString($row['qty'] ?? null),
            comments: $this->nullableString($row['comments'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $orderRow
     */
    public function mapOrderDetails(array $orderRow, ?QuoteDetails $quote): OrderDetails
    {
        $orderId = $this->string($orderRow['po_id'] ?? $orderRow['order_id'] ?? '');

        return new OrderDetails(
            id: $orderId,
            orderId: $orderId,
            customerPoNumber: $this->nullableString($orderRow['customer_po_no'] ?? null),
            reference: $this->nullableString($orderRow['reference'] ?? null),
            issueDate: $this->nullableString($orderRow['po_issue_date'] ?? null),
            comments: $this->nullableString($orderRow['comments'] ?? null),
            quoteId: $this->nullableString($orderRow['quote_id'] ?? null),
            quote: $quote,
        );
    }

    public function currencyCode(mixed $currencyId): ?string
    {
        if ($currencyId === null || $currencyId === '') {
            return null;
        }

        $id = (int) $currencyId;
        if ($id <= 0) {
            return null;
        }

        return $this->currency->currencyForId($id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRaisedBy(array $row): ?string
    {
        $cusGen = (int) ($row['cus_gen'] ?? 0);
        if ($cusGen === 1) {
            return $this->nullableString($row['contact_name'] ?? $row['raised_by'] ?? null);
        }

        return $this->nullableString($row['raised_by'] ?? $row['rep_name'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveReference(array $row, bool $isOrder): ?string
    {
        if ($isOrder) {
            $ref = $this->nullableString($row['order_reference'] ?? $row['reference'] ?? null);
            if ($ref !== null) {
                return $ref;
            }

            return $this->nullableString($row['customer_po_no'] ?? null);
        }

        return $this->nullableString($row['quote_reference'] ?? $row['cref'] ?? $row['reference'] ?? null);
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
