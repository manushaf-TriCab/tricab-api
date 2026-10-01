<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Service\AssignCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class AssignCustomerContactReportTest extends TestCase
{
    public function test_first_assignment_allocates_a_yearly_number_from_the_report_date(): void
    {
        [$service, $reports] = $this->service();
        $january = $this->insert($reports, '2026-01-04', 'M');
        $september = $this->insert($reports, '2026-09-30', 'S');
        $nextYear = $this->insert($reports, '2027-03-02', 'B');

        $first = $service->execute($this->caller(), $january->publicId, $this->body($january->rowVersion));
        $second = $service->execute($this->caller(), $september->publicId, $this->body($september->rowVersion, 'icar', 'CUS', 'alex@tricab.com'));
        $otherYear = $service->execute($this->caller(), $nextYear->publicId, $this->body($nextYear->rowVersion, 'branch', 'MFG'));

        self::assertSame('assigned', $first->status);
        self::assertSame('2601-001M-SLS', $first->ccNumber);
        self::assertSame('branch', $first->route);
        self::assertSame('SLS', $first->errorCode);
        self::assertSame('alex@tricab.com', $first->assignedTo);
        self::assertSame('Alex Assignee', $first->assignedToName);
        self::assertSame('alex@tricab.com', $first->assignedToEmail);
        self::assertSame(2, $first->assignedToUserId);
        self::assertSame('thilanh@tricab.com', $first->assignedBy);
        self::assertNotNull($first->assignedAtUtc);
        self::assertNotSame($january->rowVersion, $first->rowVersion);

        self::assertSame('2609-002S-CUS', $second->ccNumber);
        self::assertSame('icar', $second->route);
        self::assertSame('2703-001B-MFG', $otherYear->ccNumber);
        self::assertSame(2, $reports->lastAllocatedSequence(2026));
        self::assertSame(1, $reports->lastAllocatedSequence(2027));
        self::assertSame(2, $reports->numberSequenceRows());
    }

    public function test_sequence_1000_is_not_truncated(): void
    {
        [$service, $reports] = $this->service();
        $reports->seedCommittedSequence(2026, 999);
        $report = $this->insert($reports, '2026-09-01', 'M');

        $assigned = $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion));

        self::assertSame('2609-1000M-SLS', $assigned->ccNumber);
        self::assertSame(1000, $reports->lastAllocatedSequence(2026));
    }

    public function test_reassignment_keeps_the_number_and_does_not_touch_the_sequence(): void
    {
        [$service, $reports] = $this->service();
        $report = $this->insert($reports, '2026-09-30', 'M');
        $assigned = $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion));

        $again = $service->execute($this->caller(), $report->publicId, $this->body($assigned->rowVersion, 'icar', 'PMW', 'thilanh@tricab.com'));

        self::assertSame('assigned', $again->status);
        self::assertSame('2609-001M-SLS', $again->ccNumber);
        self::assertSame('icar', $again->route);
        self::assertSame('PMW', $again->errorCode);
        self::assertSame('thilanh@tricab.com', $again->assignedTo);
        self::assertSame('Thilan Lakshitha', $again->assignedToName);
        self::assertSame(1, $again->assignedToUserId);
        self::assertNotSame($assigned->rowVersion, $again->rowVersion);
        self::assertSame(1, $reports->lastAllocatedSequence(2026));
        self::assertSame(1, $reports->numberSequenceRows());
    }

    public function test_completed_and_closed_reports_cannot_be_assigned(): void
    {
        [$service, $reports] = $this->service();
        $source = $this->insert($reports, '2026-09-30', 'M');
        foreach (['completed', 'closed'] as $status) {
            $copy = $reports->seedCopy($source, 1, 'thilanh@tricab.com', $status, '2609-010M-SLS', 'branch', 'SLS', 'alex@tricab.com');
            try {
                $service->execute($this->caller(), $copy->publicId, $this->body($copy->rowVersion));
                self::fail($status . ' was assigned');
            } catch (CustomerContactReportConflict $conflict) {
                self::assertSame(CustomerContactReportConflict::NOT_ASSIGNABLE, $conflict->errorCode);
            }
        }

        self::assertSame(0, $reports->lastAllocatedSequence(2026));
    }

    public function test_a_stale_row_version_conflicts_and_does_not_allocate_twice(): void
    {
        [$service, $reports] = $this->service();
        $report = $this->insert($reports, '2026-09-30', 'M');
        $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion));

        try {
            $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion, 'icar', 'CUS'));
            self::fail('stale assignment succeeded');
        } catch (CustomerContactReportConflict $conflict) {
            self::assertSame(CustomerContactReportConflict::STALE, $conflict->errorCode);
        }

        self::assertSame(1, $reports->lastAllocatedSequence(2026));
    }

    public function test_a_failure_after_sequence_allocation_rolls_the_number_back(): void
    {
        [$service, $reports] = $this->service();
        $report = $this->insert($reports, '2026-09-30', 'M');
        $reports->interruptNextAssignmentAfterSequenceAllocation();

        try {
            $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion));
            self::fail('interrupted assignment succeeded');
        } catch (RuntimeException $exception) {
            self::assertSame('assignment interrupted after sequence allocation', $exception->getMessage());
        }

        $stored = $reports->findVisible(
            CustomerContactReportPublicId::fromString($report->publicId),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        );
        self::assertNotNull($stored);
        self::assertSame('submitted', $stored->status);
        self::assertNull($stored->ccNumber);
        self::assertNull($stored->assignedTo);
        self::assertSame($report->rowVersion, $stored->rowVersion);
        self::assertSame(0, $reports->lastAllocatedSequence(2026));
        self::assertSame(0, $reports->numberSequenceRows());

        $retried = $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion));
        self::assertSame('2609-001M-SLS', $retried->ccNumber);
    }

    public function test_an_unknown_assignee_is_rejected(): void
    {
        [$service, $reports] = $this->service();
        $report = $this->insert($reports, '2026-09-30', 'M');

        $this->expectException(\TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAssigneeNotFound::class);
        $service->execute($this->caller(), $report->publicId, $this->body($report->rowVersion, assignedTo: 'missing@tricab.com'));
    }

    /**
     * @return array{0: AssignCustomerContactReportService, 1: InMemoryCustomerContactReportRepository}
     */
    private function service(): array
    {
        $users = new InMemoryApiUserRepository();
        $users->register(
            EmailAddress::fromString('alex@tricab.com'),
            'Alex Assignee',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('alex-seed@tricab.com')),
        );
        $reports = new InMemoryCustomerContactReportRepository();
        $service = new AssignCustomerContactReportService(
            $reports,
            new IdentityCustomerContactReportAssigneeDirectory($users),
            FixedClock::utc('2026-09-30 01:00:00'),
        );

        return [$service, $reports];
    }

    private function insert(
        InMemoryCustomerContactReportRepository $reports,
        string $reportDate,
        string $location,
    ): \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails {
        return $reports->insert(new NewCustomerContactReport(
            publicId: CustomerContactReportPublicId::generate()->toString(),
            createdByUserId: 1,
            createdByEmail: 'thilanh@tricab.com',
            createdByName: 'Thilan Lakshitha',
            createdAtUtc: new DateTimeImmutable('2026-09-30 00:00:00', new DateTimeZone('UTC')),
            reportDate: new DateTimeImmutable($reportDate, new DateTimeZone('UTC')),
            location: $location,
            customerCode: 'CUST1',
            customerContact: null,
            tricabRefTrace: null,
            traceNum: null,
            conNoteNo: null,
            tricabContact: null,
            productCode: 'CAB-1',
            description: 'Cable failed',
        ));
    }

    /**
     * @return array<string, string>
     */
    private function body(
        string $rowVersion,
        string $route = 'branch',
        string $errorCode = 'SLS',
        string $assignedTo = 'alex@tricab.com',
    ): array {
        return [
            'route' => $route,
            'errorCode' => $errorCode,
            'assignedTo' => $assignedTo,
            'rowVersion' => $rowVersion,
        ];
    }

    private function caller(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            1,
            '550e8400-e29b-41d4-a716-446655440000',
            'thilanh@tricab.com',
            'Thilan Lakshitha',
            'active',
            ['EMPLOYEE'],
            ['ACCOUNT.LOGIN'],
        );
    }
}
