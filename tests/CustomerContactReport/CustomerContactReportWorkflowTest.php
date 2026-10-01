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
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotAssignee;
use TriCab\Core\CustomerContactReport\Application\Service\AssignCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CloseCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\ReopenCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CustomerContactReportWorkflowTest extends TestCase
{
    public function test_the_assignee_completes_without_touching_the_number(): void
    {
        [$assign, $complete, $reports] = $this->workflow();
        $report = $this->insert($reports);
        $assigned = $assign->execute($this->thilan(), $report->publicId, $this->assignBody($report->rowVersion, 'alex@tricab.com'));

        $completed = $complete->execute($this->alex(), $assigned->publicId, [
            'assigneeActionTaken' => 'Replaced the lug',
            'assigneeFindings' => 'Strand was nicked',
            'rowVersion' => $assigned->rowVersion,
        ]);

        self::assertSame('completed', $completed->status);
        self::assertSame('2609-001M-SLS', $completed->ccNumber);
        self::assertSame('branch', $completed->route);
        self::assertSame('SLS', $completed->errorCode);
        self::assertSame('alex@tricab.com', $completed->assignedTo);
        self::assertSame(2, $completed->assignedToUserId);
        self::assertSame('Replaced the lug', $completed->assigneeActionTaken);
        self::assertSame('Strand was nicked', $completed->assigneeFindings);
        self::assertNotNull($completed->assigneeCompletedAtUtc);
        self::assertSame('2026-09-30 01:00:00', $completed->assigneeCompletedAtUtc->format('Y-m-d H:i:s'));
        self::assertNotSame($assigned->rowVersion, $completed->rowVersion);
        self::assertSame(1, $reports->lastAllocatedSequence(2026));
        self::assertSame(1, $reports->numberSequenceRows());
    }

    public function test_complete_rejects_another_employee_and_illegal_states(): void
    {
        [$assign, $complete, $reports] = $this->workflow();
        $report = $this->insert($reports);
        $assigned = $assign->execute($this->thilan(), $report->publicId, $this->assignBody($report->rowVersion, 'alex@tricab.com'));

        try {
            $complete->execute($this->thilan(), $assigned->publicId, ['rowVersion' => $assigned->rowVersion]);
            self::fail('a different employee completed the report');
        } catch (CustomerContactReportNotAssignee) {
            self::assertSame('assigned', $this->stored($reports, $assigned->publicId)->status);
        }

        $injected = $assigned;
        try {
            $complete->execute($this->alex(), $assigned->publicId, [
                'rowVersion' => $assigned->rowVersion,
                'completedAt' => '2020-01-01T00:00:00Z',
                'icarOperatorName' => 'Someone Else',
            ]);
            self::fail('client completedAt was accepted');
        } catch (CustomerContactReportClientFieldRejected) {
            $injected = $this->stored($reports, $assigned->publicId);
            self::assertNull($injected->assigneeCompletedAtUtc);
        }

        foreach (['submitted', 'completed', 'closed'] as $status) {
            $copy = $reports->seedCopy($report, 2, 'alex@tricab.com', $status, $status === 'submitted' ? null : '2609-010M-SLS', $status === 'submitted' ? null : 'branch', $status === 'submitted' ? null : 'SLS', $status === 'submitted' ? null : 'alex@tricab.com');
            if ($status !== 'submitted') {
                $copy = $reports->seedActiveSignature($copy->publicId, 'Sam', '2026-09-01', 'https://files.example/sig.png');
                $copy = $this->stored($reports, $copy->publicId);
            }
            try {
                $complete->execute($this->alex(), $copy->publicId, ['rowVersion' => $copy->rowVersion, 'assigneeActionTaken' => 'no']);
                self::fail($status . ' was completed');
            } catch (CustomerContactReportConflict $conflict) {
                self::assertSame(CustomerContactReportConflict::NOT_COMPLETABLE, $conflict->errorCode);
            }
        }

        try {
            $complete->execute($this->alex(), $assigned->publicId, ['rowVersion' => $report->rowVersion]);
            self::fail('stale completion succeeded');
        } catch (CustomerContactReportConflict $conflict) {
            self::assertSame(CustomerContactReportConflict::STALE, $conflict->errorCode);
        }

        self::assertSame(1, $reports->lastAllocatedSequence(2026));
    }

    public function test_close_and_reopen_keep_the_number_and_follow_legacy_field_rules(): void
    {
        [$assign, $complete, $reports, $close, $reopen] = $this->workflow();
        $report = $this->insert($reports);
        $assigned = $assign->execute($this->thilan(), $report->publicId, $this->assignBody($report->rowVersion, 'alex@tricab.com', 'icar', 'CUS'));
        $completed = $complete->execute($this->alex(), $assigned->publicId, [
            'assigneeActionTaken' => 'Checked the crimp',
            'assigneeFindings' => 'Tool was worn',
            'rowVersion' => $assigned->rowVersion,
        ]);
        $signed = $reports->seedActiveSignature($completed->publicId, 'Sam Operator', '2026-09-01', 'https://files.example/sig.png');

        foreach (['submitted', 'assigned', 'closed'] as $status) {
            $copy = $reports->seedCopy($report, 1, 'thilanh@tricab.com', $status, $status === 'submitted' ? null : '2609-020M-SLS', $status === 'submitted' ? null : 'branch', $status === 'submitted' ? null : 'SLS', $status === 'submitted' ? null : 'alex@tricab.com');
            try {
                $close->execute($this->thilan(), $copy->publicId, ['rowVersion' => $copy->rowVersion]);
                self::fail($status . ' was closed');
            } catch (CustomerContactReportConflict $conflict) {
                self::assertSame(CustomerContactReportConflict::NOT_CLOSABLE, $conflict->errorCode);
            }
        }

        try {
            $close->execute($this->thilan(), $signed->publicId, ['rowVersion' => $completed->rowVersion]);
            self::fail('stale close succeeded');
        } catch (CustomerContactReportConflict $conflict) {
            self::assertSame(CustomerContactReportConflict::STALE, $conflict->errorCode);
        }

        $closed = $close->execute($this->thilan(), $signed->publicId, ['rowVersion' => $signed->rowVersion]);
        self::assertSame('closed', $closed->status);
        self::assertSame('2609-001M-CUS', $closed->ccNumber);
        self::assertSame('thilanh@tricab.com', $closed->closedBy);
        self::assertSame(1, $closed->closedByUserId);
        self::assertNotNull($closed->closedDate);
        self::assertSame('2026-09-30', $closed->closedDate->format('Y-m-d'));
        self::assertNotNull($closed->closedAtUtc);
        self::assertSame('Checked the crimp', $closed->assigneeActionTaken);
        self::assertSame('https://files.example/sig.png', $closed->icarSignatureUrl);
        self::assertSame(1, $reports->lastAllocatedSequence(2026));

        $reopened = $reopen->execute($closed->publicId, ['rowVersion' => $closed->rowVersion]);
        self::assertSame('assigned', $reopened->status);
        self::assertSame('2609-001M-CUS', $reopened->ccNumber);
        self::assertSame('icar', $reopened->route);
        self::assertSame('CUS', $reopened->errorCode);
        self::assertSame('alex@tricab.com', $reopened->assignedTo);
        self::assertSame(2, $reopened->assignedToUserId);
        self::assertSame('Checked the crimp', $reopened->assigneeActionTaken);
        self::assertSame('Tool was worn', $reopened->assigneeFindings);
        self::assertNull($reopened->assigneeCompletedAtUtc);
        self::assertSame('thilanh@tricab.com', $reopened->closedBy);
        self::assertSame(1, $reopened->closedByUserId);
        self::assertNotNull($reopened->closedDate);
        self::assertNull($reopened->closedAtUtc);
        self::assertNull($reopened->icarOperatorName);
        self::assertNull($reopened->icarSignedAt);
        self::assertNull($reopened->icarSignatureUrl);
        self::assertNotSame($closed->rowVersion, $reopened->rowVersion);

        $kept = $reports->signatureSupersessions($reopened->publicId);
        self::assertCount(1, $kept);
        self::assertSame('https://files.example/sig.png', $kept[0]->fileUrl);
        self::assertSame('signature-superseded-2026-09-01.png', $kept[0]->fileName);
        self::assertSame(0, $kept[0]->fileSize);
        self::assertSame('image/png', $kept[0]->contentType);
        self::assertSame('signature_superseded', $kept[0]->kind);
        self::assertSame(1, $reports->lastAllocatedSequence(2026));

        try {
            $reopen->execute($reopened->publicId, ['rowVersion' => $reopened->rowVersion]);
            self::fail('assigned report was reopened');
        } catch (CustomerContactReportConflict $conflict) {
            self::assertSame(CustomerContactReportConflict::NOT_REOPENABLE, $conflict->errorCode);
        }
    }

    public function test_reopen_from_completed_without_a_signature_keeps_operator_text(): void
    {
        [$assign, $complete, $reports, , $reopen] = $this->workflow();
        $report = $this->insert($reports);
        $assigned = $assign->execute($this->thilan(), $report->publicId, $this->assignBody($report->rowVersion, 'thilanh@tricab.com'));
        $completed = $complete->execute($this->thilan(), $assigned->publicId, [
            'rowVersion' => $assigned->rowVersion,
            'assigneeFindings' => 'No drawing',
        ]);
        $noted = $reports->seedActiveSignature($completed->publicId, 'Kept Name', '2026-08-01', '');

        $reopened = $reopen->execute($noted->publicId, ['rowVersion' => $noted->rowVersion]);

        self::assertSame('assigned', $reopened->status);
        self::assertSame('2609-001M-SLS', $reopened->ccNumber);
        self::assertSame('Kept Name', $reopened->icarOperatorName);
        self::assertSame('2026-08-01', $reopened->icarSignedAt);
        self::assertNull($reopened->icarSignatureUrl);
        self::assertSame('No drawing', $reopened->assigneeFindings);
        self::assertNull($reopened->assigneeCompletedAtUtc);
        self::assertSame([], $reports->signatureSupersessions($reopened->publicId));
        self::assertSame(1, $reports->lastAllocatedSequence(2026));
    }

    public function test_a_failed_signature_copy_rolls_back_with_the_reopen(): void
    {
        [$assign, $complete, $reports, $close, $reopen] = $this->workflow();
        $report = $this->insert($reports);
        $assigned = $assign->execute($this->thilan(), $report->publicId, $this->assignBody($report->rowVersion, 'alex@tricab.com', 'icar'));
        $completed = $complete->execute($this->alex(), $assigned->publicId, [
            'rowVersion' => $assigned->rowVersion,
            'assigneeActionTaken' => 'Signed',
        ]);
        $signed = $reports->seedActiveSignature($completed->publicId, 'Sam', '2026-09-01', 'https://files.example/sig.png');
        $closed = $close->execute($this->thilan(), $signed->publicId, ['rowVersion' => $signed->rowVersion]);
        $reports->interruptNextReopenAfterSignatureAttachment();

        try {
            $reopen->execute($closed->publicId, ['rowVersion' => $closed->rowVersion]);
            self::fail('interrupted reopen succeeded');
        } catch (RuntimeException $exception) {
            self::assertSame('reopen interrupted after signature attachment', $exception->getMessage());
        }

        $stored = $this->stored($reports, $closed->publicId);
        self::assertSame('closed', $stored->status);
        self::assertSame('https://files.example/sig.png', $stored->icarSignatureUrl);
        self::assertSame('Sam', $stored->icarOperatorName);
        self::assertSame($closed->rowVersion, $stored->rowVersion);
        self::assertSame([], $reports->signatureSupersessions($closed->publicId));
        self::assertSame(1, $reports->lastAllocatedSequence(2026));

        $retried = $reopen->execute($closed->publicId, ['rowVersion' => $closed->rowVersion]);
        self::assertSame('assigned', $retried->status);
        self::assertNull($retried->icarSignatureUrl);
        self::assertCount(1, $reports->signatureSupersessions($closed->publicId));
    }

    public function test_submitted_and_assigned_cannot_be_reopened(): void
    {
        [$assign, , $reports, , $reopen] = $this->workflow();
        $report = $this->insert($reports);
        try {
            $reopen->execute($report->publicId, ['rowVersion' => $report->rowVersion]);
            self::fail('submitted report was reopened');
        } catch (CustomerContactReportConflict $conflict) {
            self::assertSame(CustomerContactReportConflict::NOT_REOPENABLE, $conflict->errorCode);
        }

        $assigned = $assign->execute($this->thilan(), $report->publicId, $this->assignBody($report->rowVersion));
        try {
            $reopen->execute($assigned->publicId, ['rowVersion' => $assigned->rowVersion]);
            self::fail('assigned report was reopened');
        } catch (CustomerContactReportConflict $conflict) {
            self::assertSame(CustomerContactReportConflict::NOT_REOPENABLE, $conflict->errorCode);
        }

        self::assertSame(1, $reports->lastAllocatedSequence(2026));
    }

    /**
     * @return array{0: AssignCustomerContactReportService, 1: CompleteCustomerContactReportService, 2: InMemoryCustomerContactReportRepository, 3: CloseCustomerContactReportService, 4: ReopenCustomerContactReportService}
     */
    private function workflow(): array
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
        $clock = FixedClock::utc('2026-09-30 01:00:00');
        $directory = new IdentityCustomerContactReportAssigneeDirectory($users);

        return [
            new AssignCustomerContactReportService($reports, $directory, $clock),
            new CompleteCustomerContactReportService($reports, $clock),
            $reports,
            new CloseCustomerContactReportService($reports, $clock),
            new ReopenCustomerContactReportService($reports, $clock),
        ];
    }

    private function insert(InMemoryCustomerContactReportRepository $reports): CustomerContactReportDetails
    {
        return $reports->insert(new NewCustomerContactReport(
            publicId: CustomerContactReportPublicId::generate()->toString(),
            createdByUserId: 1,
            createdByEmail: 'thilanh@tricab.com',
            createdByName: 'Thilan Lakshitha',
            createdAtUtc: new DateTimeImmutable('2026-09-30 00:00:00', new DateTimeZone('UTC')),
            reportDate: new DateTimeImmutable('2026-09-30', new DateTimeZone('UTC')),
            location: 'M',
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
    private function assignBody(string $rowVersion, string $assignedTo = 'alex@tricab.com', string $route = 'branch', string $errorCode = 'SLS'): array
    {
        return [
            'route' => $route,
            'errorCode' => $errorCode,
            'assignedTo' => $assignedTo,
            'rowVersion' => $rowVersion,
        ];
    }

    private function stored(InMemoryCustomerContactReportRepository $reports, string $publicId): CustomerContactReportDetails
    {
        $stored = $reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        );
        self::assertNotNull($stored);

        return $stored;
    }

    private function thilan(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, '550e8400-e29b-41d4-a716-446655440000', 'thilanh@tricab.com', 'Thilan Lakshitha', 'active', ['EMPLOYEE'], ['ACCOUNT.LOGIN']);
    }

    private function alex(): AuthenticatedUser
    {
        return new AuthenticatedUser(2, '550e8400-e29b-41d4-a716-446655440001', 'alex@tricab.com', 'Alex Assignee', 'active', ['EMPLOYEE'], ['ACCOUNT.LOGIN']);
    }
}
