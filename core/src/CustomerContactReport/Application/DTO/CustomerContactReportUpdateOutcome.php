<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class CustomerContactReportUpdateOutcome
{
    public function __construct(
        public CustomerContactReportDetails $report,
        /** True when a decided credit went back to pending and any Finance step was removed. */
        public bool $creditReset,
        public ?CustomerContactReportApproval $creditApproval,
    ) {
    }
}
