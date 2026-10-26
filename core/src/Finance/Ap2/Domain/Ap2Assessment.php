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
     * @param list<string> $exportIssues why the Odoo CSV export is refused (empty when eligible)
     * @param list<string> $paymentIssues why a payment cannot be proposed (empty when none)
     */
    public function __construct(
        public array $validation,
        public string $matching,
        public array $duplicateOf,
        public string $queue,
        public string $fingerprint,
        public bool $approvalCurrent,
        public bool $csvEligible,
        public array $exportIssues,
        public array $paymentIssues,
        public float $balance,
        public bool $locked,
        public int $daysOverdue,
    ) {
    }
}
