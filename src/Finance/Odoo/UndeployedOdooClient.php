<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Odoo;

use TriCab\Core\Finance\Shared\Odoo\OdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * The client while Odoo is not configured (`ODOO_URL`, `ODOO_API_KEY` or `ODOO_COMPANY_IDS`
 * empty): every read says so and nothing touches the network.
 */
final class UndeployedOdooClient implements OdooClient
{
    public const REASON = 'Odoo is not configured (ODOO_URL, ODOO_API_KEY, ODOO_COMPANY_IDS).';

    public function searchRead(string $model, array $domain, array $fields): array
    {
        throw new OdooError(self::REASON);
    }
}
