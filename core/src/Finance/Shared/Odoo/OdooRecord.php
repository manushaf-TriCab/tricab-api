<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;

/**
 * A synced record as the apps read it: the payload plus what the sync knows about it.
 */
final readonly class OdooRecord
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $listKey,
        public int $odooId,
        public array $payload,
        public bool $isActive,
        public bool $isDeleted,
        public ?DateTimeImmutable $writeDate,
        public DateTimeImmutable $syncedAt,
    ) {
    }

    /**
     * The shape the portal's reference route returned (`record_payload`): the payload with
     * `odoo_id`, `active`, `deleted`, `write_date` and `synced_at` added, times in UTC with
     * an explicit offset.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload + [
            'odoo_id' => $this->odooId,
            'active' => $this->isActive,
            'deleted' => $this->isDeleted,
            'write_date' => $this->writeDate?->format('c'),
            'synced_at' => $this->syncedAt->format('c'),
        ];
    }
}
