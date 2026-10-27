<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\FourJs;

/**
 * An order confirmation or an invoice. $gstRate is a fraction (0.10) and is
 * only known for invoices. $currency is the 4JS currency code (AUD, NZD).
 */
final readonly class FourJsDocument
{
    /**
     * @param list<FourJsLine> $lines
     */
    public function __construct(
        public string $type,
        public string $orderNo,
        public string $invoiceNo,
        public string $customerCode,
        public string $customerName,
        public string $contact,
        public string $customerPo,
        public string $currency,
        public ?string $gstRate,
        public string $total,
        public array $lines,
    ) {
    }

    public function isInvoice(): bool
    {
        return $this->type === 'invoice';
    }
}
