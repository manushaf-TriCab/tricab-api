<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Mail;

use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;

/** The registry held in memory: the default where no database is configured, and the tests'. */
final class InMemoryFinanceMailboxRegistry implements FinanceMailboxRegistry
{
    /** @var array<string, FinanceMailbox> keyed by lower-case address */
    private array $mailboxes = [];

    /**
     * @param list<FinanceMailbox> $mailboxes
     */
    public function __construct(array $mailboxes = [])
    {
        foreach ($mailboxes as $mailbox) {
            $this->mailboxes[strtolower($mailbox->address)] = $mailbox;
        }
    }

    public function find(string $address): ?FinanceMailbox
    {
        return $this->mailboxes[strtolower(trim($address))] ?? null;
    }

    public function all(): array
    {
        $all = array_values($this->mailboxes);
        usort($all, static fn (FinanceMailbox $a, FinanceMailbox $b): int => strcmp($a->address, $b->address));

        return $all;
    }
}
