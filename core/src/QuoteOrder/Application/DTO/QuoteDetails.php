<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

final readonly class QuoteDetails
{
    /**
     * @param list<QuoteItemDetails> $items
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $quoteId,
        public ?string $orderId,
        public ?string $date,
        public ?string $validDate,
        public ?string $raisedBy,
        public ?string $representative,
        public ?string $customerCode,
        public ?string $customerName,
        public ?string $contactName,
        public ?string $reference,
        public string $status,
        public string $statusCode,
        public string $statusLabel,
        public ?string $total,
        public string $totalStatus,
        public ?string $currency,
        public array $items = [],
    ) {
    }

    public function toSummary(): QuoteOrderSummary
    {
        return new QuoteOrderSummary(
            id: $this->id,
            type: $this->type,
            quoteId: $this->quoteId,
            orderId: $this->orderId,
            date: $this->date,
            raisedBy: $this->raisedBy,
            representative: $this->representative,
            customerCode: $this->customerCode,
            customerName: $this->customerName,
            reference: $this->reference,
            status: $this->status,
            statusCode: $this->statusCode,
            statusLabel: $this->statusLabel,
            total: $this->total,
            totalStatus: $this->totalStatus,
            currency: $this->currency,
        );
    }
}
