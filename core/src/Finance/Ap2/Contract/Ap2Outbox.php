<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2OutboxMessage;

/**
 * Where Tri-AP2 hands a message it wants emailed: the approval request, the decision notice, a shared history.
 * It is called inside the use case's transaction, so a message is queued if and only if the write happened.
 *
 * Two implementations: {@see \TriCab\Api\Finance\Ap2\HistoryOnlyAp2Outbox} (nothing is sent; it is written into the
 * supplier's history) and {@see \TriCab\Core\Finance\Ap2\Service\Ap2MailOutbox} (Graph, from the AP mailbox, only when
 * FINANCE_AP2_SENDING_ENABLED is true).
 */
interface Ap2Outbox
{
    public function queue(Ap2OutboxMessage $message): void;
}
