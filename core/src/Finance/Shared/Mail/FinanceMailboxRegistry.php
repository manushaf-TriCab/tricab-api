<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/** The registry of shared mailboxes and the grants on them. Read-only in Phase 1. */
interface FinanceMailboxRegistry
{
    /** The mailbox at this address (any case), active or not, or null. */
    public function find(string $address): ?FinanceMailbox;

    /** @return list<FinanceMailbox> every mailbox, by address, with its grants */
    public function all(): array;
}
