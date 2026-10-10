<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use TriCab\Core\Finance\Shared\Odoo\OdooRecord;

/**
 * One Odoo vendor as the matching rules read it: the fields of a `finance.odoo_records` row of the
 * `vendors` list that `vendor_match.py` looks at, nothing more.
 */
final readonly class ApVendor
{
    public function __construct(
        public int $odooId,
        public string $name,
        public string $ref = '',
        public string $email = '',
        public string $vat = '',
        public string $companyName = '',
        public bool $active = true,
        public bool $deleted = false,
    ) {
    }

    /** From a synced record: the payload's text fields, with the record's own active and deleted flags. */
    public static function fromRecord(OdooRecord $record): self
    {
        return new self(
            $record->odooId,
            self::text($record->payload['name'] ?? null),
            self::text($record->payload['ref'] ?? null),
            self::text($record->payload['email'] ?? null),
            self::text($record->payload['vat'] ?? null),
            self::text($record->payload['company_name'] ?? null),
            $record->isActive,
            $record->isDeleted,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) && $value !== false ? (string) $value : '';
    }
}
