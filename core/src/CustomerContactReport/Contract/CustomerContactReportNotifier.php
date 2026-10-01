<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Contract;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotice;

/**
 * Delivers one already-built Customer Contact notice.
 * Implementations may throw. Callers commit the business change first and
 * treat a throw as a logged, non-fatal delivery failure.
 */
interface CustomerContactReportNotifier
{
    public function send(CustomerContactReportNotice $notice): void;
}
