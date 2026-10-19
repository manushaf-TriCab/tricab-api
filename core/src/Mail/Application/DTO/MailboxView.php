<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailMailbox`. */
final readonly class MailboxView
{
    public function __construct(
        public string $id,
        public string $address,
        public string $displayName,
        public string $connection,
        public ?DateTimeImmutable $lastSyncAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'address' => $this->address,
            'displayName' => $this->displayName,
            'connection' => $this->connection,
            'lastSyncAt' => $this->lastSyncAt === null ? null : MailTime::iso($this->lastSyncAt),
        ];
    }
}
