<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceRecordGuard;

/**
 * Tri-AR's answer to "may this caller see that record?" for the file store. A caller who reached the route
 * already holds the app's permission; the record must also exist (a binned record is still readable, T5.0
 * finding 11), and only the app's own record type is answered.
 */
final class ArRecordGuard implements FinanceRecordGuard
{
    public function __construct(private readonly ArRecordStore $records)
    {
    }

    public function maySee(string $application, string $recordType, string $recordId): bool
    {
        return $application === Module::APP_AR
            && $recordType === ArRecordService::ATTACHMENT_RECORD_TYPE
            && $this->records->exists($recordId);
    }
}
