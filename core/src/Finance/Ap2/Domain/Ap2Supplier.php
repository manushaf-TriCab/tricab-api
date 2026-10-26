<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.ap2_suppliers`.
 *
 * `bankVerified` and `bankChange` are changed only by the bank-verification route (permission BANK.VERIFY), never by
 * a supplier PATCH: they are part of the invoice fingerprint, so an approval goes stale when they move.
 */
final readonly class Ap2Supplier
{
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $industry = '',
        public string $email = '',
        public string $contact = '',
        public string $cc = '',
        public string $entity = '',
        public string $phone = '',
        public int $terms = 30,
        public ?Ap2Person $owner = null,
        public bool $bankVerified = false,
        public bool $bankChange = false,
        public bool $hold = false,
        public string $notes = '',
        public ?int $odooPartnerId = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
