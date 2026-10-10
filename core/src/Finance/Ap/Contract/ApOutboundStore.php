<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;

/**
 * `finance.invoice_outbound_messages`: which mail-ledger rows were sent about which invoice, email or approval batch, with the body.
 * Storage only: the send, the grant check, the sending switch and the audit events are `ApMailService`'s.
 *
 * The approvals task records its mail here with {@see ApOutboundTarget::approvalBatch()}, through `ApMailService::deliver()`.
 */
interface ApOutboundStore
{
    /**
     * Runs `$work` as one unit (a link and the audit event that goes with it). A call inside another joins it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    public function add(ApOutboundMessage $message): void;

    /**
     * The mail recorded against one record, newest first, at most `$limit` (invoice history 100, email history 50).
     *
     * @return list<ApOutboundMessage>
     */
    public function of(ApOutboundTarget $target, int $limit): array;

    /** The link to this ledger row, or null: how a repeated idempotency key finds the first send's link. */
    public function findByDelivery(string $deliveryId): ?ApOutboundMessage;
}
