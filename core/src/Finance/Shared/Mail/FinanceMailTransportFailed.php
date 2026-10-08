<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * The transport did not send (the portal's 502). Thrown by a transport with no
 * delivery; the service sets `delivery` to the ledger row it marked failed
 * before rethrowing.
 */
final class FinanceMailTransportFailed extends FinanceMailException
{
    public ?FinanceMailDelivery $delivery = null;
}
