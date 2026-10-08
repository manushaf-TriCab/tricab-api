<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

use TriCab\Core\Finance\Ar\Domain\ArOutboundMessage;

/** `ar_outbound_messages`: which ledger rows were sent about which record, with the body. */
interface ArOutboundStore
{
    public function addOutbound(ArOutboundMessage $message): void;

    /**
     * @return list<ArOutboundMessage> a record's sent mail, newest first
     */
    public function outboundOf(string $recordId): array;
}
