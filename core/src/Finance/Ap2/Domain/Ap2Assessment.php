<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/**
 * Everything the rules say about one invoice at one moment: the contract's computed fields. Never stored.
 */
final readonly class Ap2Assessment
{
    /**
     * @param list<string> $validation   the prototype's `validation()` messages
     * @param list<string> $duplicateOf  ids of the other invoices this one is a suspected duplicate of
     * @param string       $businessStatus OK, Required, Pending, Approved, Rejected or Required / stale
     * @param list<string> $postingIssues why posting to Odoo through the API is refused (empty when it may be posted)
     * @param list<string> $paymentIssues why a payment cannot be proposed (empty when none)
     */
    public function __construct(
        public array $validation,
        public string $matching,
        public array $duplicateOf,
        public string $queue,
        public string $fingerprint,
        public bool $approvalCurrent,
        public string $businessStatus,
        public bool $postable,
        public array $postingIssues,
        public array $paymentIssues,
        public float $balance,
        public bool $locked,
        public int $daysOverdue,
    ) {
    }
}
