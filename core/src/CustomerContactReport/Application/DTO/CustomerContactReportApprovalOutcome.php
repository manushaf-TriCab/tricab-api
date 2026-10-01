<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class CustomerContactReportApprovalOutcome
{
    public function __construct(
        public CustomerContactReportDetails $report,
        public CustomerContactReportApproval $approval,
        public ?CustomerContactReportApproval $settlement,
    ) {
    }
}
