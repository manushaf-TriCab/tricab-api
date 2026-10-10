<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_outbound_messages` (decision D73): a link from an invoice, an email or an approval batch to the
 * mail ledger row of a message sent from Tri-AP, keeping the body (plain text for vendor mail and replies, HTML for approval mail)
 * so the pages can show what went out. The subject, recipients, status, error and sent time are the ledger's. Exactly one of the
 * three record ids is set.
 */
final readonly class ApOutboundMessage
{
    public function __construct(
        public string $id,
        public ApOutboundTarget $target,
        public string $deliveryId,
        public ?int $senderUserId,
        public string $body,
        public bool $attachInvoice,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
