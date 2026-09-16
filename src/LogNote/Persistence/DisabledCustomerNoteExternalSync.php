<?php

declare(strict_types=1);

namespace TriCab\Api\LogNote\Persistence;

use TriCab\Core\LogNote\Contract\CustomerNoteExternalSync;
use TriCab\Core\LogNote\Domain\Entity\LogNote;

/**
 * Phase 1 integration boundary implementation.
 *
 * 4JS sync intentionally disabled pending explicit integration approval.
 * This class must never write to 4JS, SSH, filesystem, NetSuite, or ERP.
 */
final class DisabledCustomerNoteExternalSync implements CustomerNoteExternalSync
{
    private int $invocations = 0;

    public function syncAfterCreate(LogNote $note): void
    {
        // 4JS sync intentionally disabled pending explicit integration approval.
        $this->invocations++;
        unset($note);
    }

    public function invocationCount(): int
    {
        return $this->invocations;
    }
}
