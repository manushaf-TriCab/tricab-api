<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** A message Tri-AP2 wants sent: an approval request, a decision notice or a shared history. */
final readonly class Ap2OutboxMessage
{
    public const APPROVAL_REQUEST = 'Approval request';

    public const DECISION_NOTICE = 'Decision notice';

    public const HISTORY_SHARE = 'History share';

    public function __construct(
        public string $kind,
        public string $supplierId,
        public Ap2Person $from,
        public string $to,
        public string $subject,
        public string $body,
        public string $cc = '',
        public ?string $invoiceId = null,
        public ?string $mailId = null,
        /** What the message is about (the approval id), so a repeated queue of the same thing sends once. Null = every queue is its own. */
        public ?string $reference = null,
    ) {
    }
}
