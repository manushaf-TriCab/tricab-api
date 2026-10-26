<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/**
 * What the invoice rules need of an approval: its kind, its status and the fingerprint the approver saw. The
 * approval workflow itself arrives in a later phase; until then nothing writes `ap2_approvals`, and every invoice
 * reads as not approved.
 */
final readonly class Ap2ApprovalRef
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public ?string $snapshot,
    ) {
    }
}
