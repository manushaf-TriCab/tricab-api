<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * One position's signing authority (`invoice_signing_limits`, "Limits by Position"). A null limit is
 * no authority for that document type, which is not a limit of 0; 99999999999 is the source's
 * "unlimited" and is kept as it is.
 */
final readonly class ApSigningLimit
{
    public function __construct(
        public string $id,
        public string $authorityRole,
        public string $roleCode,
        public string $fullName,
        public string $email,
        public string $d365Position,
        public ?float $poInventory,
        public ?float $poNonInventory,
        public ?float $expenseClaim,
        public ?float $customerOrder,
        public ?float $creditNotes,
        public bool $active,
    ) {
    }
}
