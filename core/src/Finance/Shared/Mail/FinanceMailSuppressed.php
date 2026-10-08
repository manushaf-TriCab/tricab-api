<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * Thrown by a transport that deliberately sent nothing because mail is switched
 * off. It is not a failure: the service records the row as `suppressed`, returns
 * it, and the same idempotency key may be sent again once mail is on.
 */
final class FinanceMailSuppressed extends FinanceMailException
{
}
