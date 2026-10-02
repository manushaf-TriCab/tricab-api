<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

/**
 * The margin 4js would default a customer's quote lines to, by product code
 * (parity plan E5.3): the source's `default_margins_for_customer`, which
 * mirrors 4js's own lookup — product, then style group, then the customer's
 * country, market and territory.
 *
 * It fails soft, as the source's endpoint does: a lookup that cannot be had
 * answers nothing, and the lines keep the margin they have. A customer with
 * no rule for a style has no entry for its codes.
 */
interface RfqDefaultMargins
{
    /** A style 4js has a group for but no rule for this customer: margin by the line's cost value. */
    public const COST_RULE = 'cost';

    /**
     * @param array<string, string> $styles product code => its catalogue style, upper case
     *
     * @return array<string, int|string> product code => default margin percent, or COST_RULE
     */
    public function forCustomer(int $customerId, array $styles): array;
}
