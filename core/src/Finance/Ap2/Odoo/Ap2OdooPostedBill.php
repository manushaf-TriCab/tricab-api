<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * What posting a bill answered: the Odoo move id and name (a draft's name is "/" until Finance posts it), whether
 * this call created it or found the one an earlier call (or the CSV import) made, and whether the external id
 * `__import__.triap_<id>` is registered (false when Odoo refused that second write; the reference lookup still
 * prevents a duplicate).
 */
final readonly class Ap2OdooPostedBill
{
    public function __construct(
        public int $moveId,
        public string $name,
        public bool $created,
        public bool $externalIdRegistered = true,
    ) {
    }
}
