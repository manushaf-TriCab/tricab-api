<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * A shared mailbox in the registry (`finance.mailboxes`) with what each app may
 * do with it (`finance.mailbox_grants`).
 */
final readonly class FinanceMailbox
{
    /**
     * @param array<string, array{read: bool, send: bool}> $grants keyed by app key
     */
    public function __construct(
        public string $id,
        public string $address,
        public string $displayName,
        public string $purpose,
        public bool $active,
        public array $grants = [],
    ) {
    }

    public function canSend(string $application): bool
    {
        return $this->active && ($this->grants[$application]['send'] ?? false);
    }

    public function canRead(string $application): bool
    {
        return $this->active && ($this->grants[$application]['read'] ?? false);
    }
}
