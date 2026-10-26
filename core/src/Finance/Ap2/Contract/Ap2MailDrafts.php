<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2MailDraft;

/** `finance.ap2_mail`, only as far as Email approvals need it. Backed by the same store as {@see Ap2MailStore}. */
interface Ap2MailDrafts
{
    public function find(string $mailId): ?Ap2MailDraft;

    /** Points the mail at its current approval. */
    public function linkApproval(string $mailId, string $approvalId): void;
}
