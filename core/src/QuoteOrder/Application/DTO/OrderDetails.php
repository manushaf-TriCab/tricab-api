<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

final readonly class OrderDetails
{
    public function __construct(
        public string $id,
        public string $orderId,
        public ?string $customerPoNumber,
        public ?string $reference,
        public ?string $issueDate,
        public ?string $comments,
        public ?string $quoteId,
        public ?QuoteDetails $quote,
    ) {
    }
}
