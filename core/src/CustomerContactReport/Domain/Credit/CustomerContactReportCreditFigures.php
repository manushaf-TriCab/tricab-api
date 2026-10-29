<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\Credit;

/**
 * The money the server stores. Decimal strings with two places.
 */
final readonly class CustomerContactReportCreditFigures
{
    public function __construct(
        public string $valueExGst,
        public string $restockingAmount,
        public string $subTotal,
        public string $gst,
        public string $creditTotal,
    ) {
    }

    public static function none(): self
    {
        return new self('0.00', '0.00', '0.00', '0.00', '0.00');
    }
}
