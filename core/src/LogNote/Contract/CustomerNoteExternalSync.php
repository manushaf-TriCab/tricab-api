<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Contract;

use TriCab\Core\LogNote\Domain\Entity\LogNote;

/**
 * Integration boundary for legacy 4JS / ERP replay after a Log Note is saved.
 *
 * 4JS sync intentionally disabled pending explicit integration approval.
 * Phase 1 must not write to 4JS, SSH, filesystem, NetSuite, or ERP.
 */
interface CustomerNoteExternalSync
{
    public function syncAfterCreate(LogNote $note): void;
}
