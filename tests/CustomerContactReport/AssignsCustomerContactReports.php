<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAssignment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;

trait AssignsCustomerContactReports
{
    /**
     * Assigns a report straight through the repository (status assigned, CC number allocated).
     */
    private function assignTo(
        InMemoryCustomerContactReportRepository $reports,
        CustomerContactReportDetails $report,
        int $userId = 2,
        string $email = 'alex@tricab.com',
        string $name = 'Alex Assignee',
    ): CustomerContactReportDetails {
        return $reports->assign(new CustomerContactReportAssignment(
            publicId: $report->publicId,
            expectedRowVersion: $report->rowVersion,
            route: 'branch',
            errorCode: 'SLS',
            assigneeUserId: $userId,
            assigneeLogin: $email,
            assigneeName: $name,
            assigneeEmail: $email,
            assignedByEmail: 'thilanh@tricab.com',
            assignedAtUtc: new DateTimeImmutable('2026-09-30 00:30:00', new DateTimeZone('UTC')),
        ));
    }
}
