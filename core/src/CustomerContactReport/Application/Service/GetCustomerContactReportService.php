<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;

final class GetCustomerContactReportService
{
    public function __construct(
        private readonly CustomerContactReportRepository $reports,
    ) {
    }

    public function execute(string $publicId, CustomerContactReportVisibility $visibility): CustomerContactReportDetails
    {
        $found = $this->reports->findVisible(CustomerContactReportPublicId::fromString($publicId), $visibility);
        if ($found === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        return $found;
    }

    /**
     * @return list<CustomerContactReportApproval>
     */
    public function approvals(string $publicId): array
    {
        return $this->reports->approvalsFor($publicId);
    }
}
