<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * The idempotency key is already used by a send that did not succeed (the
 * portal's 409): `failed`, or `pending` because a send is in flight or died.
 * The caller retries under a new key. Tri-Task relies on this.
 */
final class FinanceMailConflict extends FinanceMailException
{
    public function __construct(public readonly FinanceMailDelivery $delivery)
    {
        parent::__construct(sprintf(
            'A send with this idempotency key already exists and is %s. Retry under a new key.',
            $delivery->status,
        ));
    }
}
