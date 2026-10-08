<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/**
 * One row of `ar_outbound_messages` (decision D42): a link from a record to the mail ledger row of a reply sent from
 * the composer, keeping the body so the register can show what went to a customer. The subject, recipients, status,
 * error and sent time are the ledger's.
 */
final readonly class ArOutboundMessage
{
    public function __construct(
        public string $id,
        public string $recordId,
        public string $deliveryId,
        public ?int $senderUserId,
        public string $senderUser,
        public bool $threaded,
        public string $body,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
