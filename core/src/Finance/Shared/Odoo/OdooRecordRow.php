<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;

/**
 * What a sync writes for one Odoo record (`finance.odoo_records`): the searchable
 * columns lifted out of the payload, and the payload itself as JSON.
 */
final readonly class OdooRecordRow
{
    public function __construct(
        public string $listKey,
        public int $odooId,
        /** At most 400 characters. */
        public string $name,
        /** At most 100 characters; null when the list has no code or the record has none. */
        public ?string $code,
        public ?int $companyId,
        public bool $isActive,
        /** The payload, JSON, Unicode unescaped. */
        public string $dataJson,
        public ?DateTimeImmutable $writeDate,
    ) {
    }
}
