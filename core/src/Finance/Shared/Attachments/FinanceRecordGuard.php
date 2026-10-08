<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

/**
 * Each app's answer to "may this caller see that record?", given to
 * {@see FinanceAttachmentService::download()} by the app's own route. The store
 * knows files, not records, so it cannot decide this itself, and a download that
 * does not pass a guard does not exist.
 */
interface FinanceRecordGuard
{
    public function maySee(string $application, string $recordType, string $recordId): bool;
}
