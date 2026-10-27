<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\FourJs;

/**
 * One order line as 4JS reports it. Money and quantity are decimal strings.
 * The browser never supplies these; they are always read back from 4JS.
 */
final readonly class FourJsLine
{
    public function __construct(
        public string $orderNo,
        public string $invoiceNo,
        public int $lineNo,
        public string $productCode,
        public string $description,
        public string $qty,
        public string $unit,
        public int $packs,
        public string $packSize,
        public string $unitPrice,
        public string $net,
        public string $warehouse,
        public string $comment,
    ) {
    }

    public function key(): string
    {
        return $this->orderNo . ':' . $this->lineNo;
    }

    public function withInvoice(string $invoiceNo): self
    {
        return new self(
            $this->orderNo,
            $invoiceNo,
            $this->lineNo,
            $this->productCode,
            $this->description,
            $this->qty,
            $this->unit,
            $this->packs,
            $this->packSize,
            $this->unitPrice,
            $this->net,
            $this->warehouse,
            $this->comment,
        );
    }
}
