<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * The only way Tri-AP writes to Odoo: `create` on one model, behind FINANCE_AP2_ODOO_POSTING_ENABLED. The shared
 * {@see \TriCab\Core\Finance\Shared\Odoo\OdooClient} stays read-only; this is a separate, switched-off-by-default
 * boundary so nothing else can write by accident. No update, no unlink, no workflow method (action_post).
 */
interface Ap2OdooRecordWriter
{
    /**
     * @param array<string, mixed> $values
     * @return int the new record's id
     * @throws Ap2OdooPostingDisabled
     * @throws OdooError
     */
    public function create(string $model, array $values): int;
}
