<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Contract;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredFile;

/**
 * Customer Contact files. Backed by the shared blob infrastructure.
 * This module does not call Tri Expense domain services.
 */
interface CustomerContactReportFileStore
{
    public function put(string $blobPath, string $contentType, string $bytes): CustomerContactReportStoredFile;

    public function delete(string $blobPath): void;
}
