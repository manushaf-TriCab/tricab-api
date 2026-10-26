<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.ap2_payments`: a payment PROPOSAL. Tri-AP2 prepares and controls; Odoo pays. The states after
 * approval (Sent to Odoo, Paid) are set only by the Odoo stream ({@see Service\Ap2PaymentService::recordOdooState()}).
 */
final readonly class Ap2Payment
{
    public const STATUSES = ['Proposed', 'Awaiting payment approval', 'Payment approved', 'Sent to Odoo', 'Paid', 'Cancelled', 'Rejected'];

    /** Not Cancelled, Rejected or Paid: it still counts against the cash limit and blocks a second proposal. */
    public const ACTIVE = ['Proposed', 'Awaiting payment approval', 'Payment approved', 'Sent to Odoo'];

    /** What a person may cancel. */
    public const CANCELLABLE = ['Proposed', 'Awaiting payment approval', 'Payment approved'];

    public function __construct(
        public string $id,
        public string $supplierId,
        public string $invoiceId,
        public float $amount,
        public string $currency,
        public string $date,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $status = 'Proposed',
        public ?string $approvalId = null,
        public string $odooPaymentRef = '',
        public string $rejection = '',
        public ?Ap2Person $createdBy = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
