<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

final readonly class QuoteOrderSummary
{
    public function __construct(
        public string $id,
        public string $type,
        public string $quoteId,
        public ?string $orderId,
        public ?string $date,
        public ?string $raisedBy,
        public ?string $representative,
        public ?string $customerCode,
        public ?string $customerName,
        public ?string $reference,
        public string $status,
        public string $statusCode,
        public string $statusLabel,
        public ?string $total,
        public string $totalStatus,
        public ?string $currency,
    ) {
    }
}
