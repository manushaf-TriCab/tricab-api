<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListResult;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;

final class ListCustomerContactReportsService
{
    public function __construct(
        private readonly CustomerContactReportRepository $reports,
    ) {
    }

    public function execute(
        CustomerContactReportListCriteria $criteria,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportListResult {
        return $this->reports->list($criteria, $visibility);
    }
}
