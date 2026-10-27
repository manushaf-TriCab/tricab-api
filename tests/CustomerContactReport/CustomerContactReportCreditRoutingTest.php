<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\CustomerContactReport\Storage\InMemoryCustomerContactReportFileStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportSettlementService;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactReportBlobWrite;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\RequestCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Credit routing: the credit goes to whoever is assigned the report, with no
 * manager hop and no amount threshold. Finance is raised on approval.
 */
final class CustomerContactReportCreditRoutingTest extends TestCase
{
    use AssignsCustomerContactReports;

    private RequestCustomerContactReportApprovalService $request;

    private DecideCustomerContactReportApprovalService $decide;

    private CompleteCustomerContactReportSettlementService $settlement;

    private CompleteCustomerContactReportService $complete;

    private InMemoryCustomerContactReportRepository $reports;

    private InMemoryCustomerContactReportFileStore $files;

    protected function setUp(): void
    {
        $users = new InMemoryApiUserRepository();
        foreach (['alex@tricab.com' => 'Alex Assignee', 'sam@tricab.com' => 'Sam Reviewer', 'accounts@tricab.com' => 'Accounts'] as $email => $name) {
            $users->register(
                EmailAddress::fromString($email),
                $name,
                UserStatus::active(),
                [RoleCode::fromString('EMPLOYEE')],
                [PermissionCode::fromString('ACCOUNT.LOGIN')],
                EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-' . $email)),
            );
        }
        $this->reports = new InMemoryCustomerContactReportRepository();
        $this->files = new InMemoryCustomerContactReportFileStore();
        $clock = FixedClock::utc('2026-09-30 01:00:00');
        $this->request = new RequestCustomerContactReportApprovalService($this->reports, $clock);
        $this->decide = new DecideCustomerContactReportApprovalService(
            $this->reports,
            new IdentityCustomerContactReportAssigneeDirectory($users),
            $clock,
            new CustomerContactReportAccountsLogin('accounts@tricab.com'),
        );
        $this->settlement = new CompleteCustomerContactReportSettlementService(
            $this->reports,
            new CustomerContactReportBlobWrite($this->files),
            $clock,
        );
        $this->complete = new CompleteCustomerContactReportService($this->reports, $clock);
    }

    public function test_credit_goes_to_the_assignee_whatever_the_total(): void
    {
        foreach (['999.99', '1000.00', '1000.01', '5000'] as $total) {
            $report = $this->assignTo($this->reports, $this->insert());
            $outcome = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
                'creditTotal' => $total,
                'rowVersion' => $report->rowVersion,
            ]);

            self::assertSame('alex@tricab.com', $outcome->approval->approver, $total);
            self::assertSame(2, $outcome->approval->approverUserId, $total);
            self::assertSame('pending', $outcome->approval->status, $total);
            self::assertSame('assigned', $outcome->report->status, $total);
        }

        // Another assignee gets their own credit; the raiser's line manager never enters into it.
        $report = $this->assignTo($this->reports, $this->insert(), 3, 'sam@tricab.com', 'Sam Reviewer');
        $outcome = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'creditTotal' => '1000.01',
            'rowVersion' => $report->rowVersion,
        ]);
        self::assertSame('sam@tricab.com', $outcome->approval->approver);
    }

    public function test_a_credit_on_a_submitted_report_is_refused(): void
    {
        $report = $this->insert();
        try {
            $this->request->execute($this->raiser(), $report->publicId, 'credit', [
                'creditTotal' => '10.00',
                'rowVersion' => $report->rowVersion,
            ]);
            self::fail('A credit on an unassigned report was accepted.');
        } catch (CustomerContactReportApprovalFailure $failure) {
            self::assertSame(CustomerContactReportApprovalFailure::CONFIGURATION, $failure->errorCode);
        }
        self::assertSame([], $this->reports->approvalsFor($report->publicId));
    }

    public function test_a_credit_request_without_any_total_is_accepted(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $outcome = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'rowVersion' => $report->rowVersion,
        ]);

        self::assertSame('pending', $outcome->approval->status);
        self::assertSame('alex@tricab.com', $outcome->approval->approver);
        self::assertNull($outcome->report->creditTotal);
    }

    public function test_an_approver_in_the_payload_is_rejected(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $this->expectException(CustomerContactReportClientFieldRejected::class);
        try {
            $this->request->execute($this->raiser(), $report->publicId, 'credit', [
                'approver' => 'sam@tricab.com',
                'creditTotal' => '10.00',
                'rowVersion' => $report->rowVersion,
            ]);
        } finally {
            self::assertSame([], $this->reports->approvalsFor($report->publicId));
        }
    }

    public function test_rework_cannot_be_requested_but_an_old_pending_row_still_loads_and_decides(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        try {
            $this->request->execute($this->raiser(), $report->publicId, 'rework', [
                'rowVersion' => $report->rowVersion,
            ]);
            self::fail('rework was requestable.');
        } catch (InvalidCustomerContactReportValue $failure) {
            self::assertStringContainsString('rework is retired', $failure->getMessage());
        }
        self::assertSame([], $this->reports->approvalsFor($report->publicId));

        $seeded = $this->reports->requestApproval(new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest(
            publicId: $report->publicId,
            expectedReportRowVersion: $report->rowVersion,
            kind: 'rework',
            approverLogin: 'alex@tricab.com',
            approverUserId: 2,
            approverName: 'Alex Assignee',
            approverEmail: 'alex@tricab.com',
            requestedBy: 'thilanh@tricab.com',
            requestedByUserId: 1,
            requestedAtUtc: new DateTimeImmutable('2026-09-01 01:00:00', new DateTimeZone('UTC')),
            writeCreditFigures: false,
            valueExGst: null,
            restockingAmount: null,
            subTotal: null,
            gst: null,
            creditTotal: null,
        ));
        $loaded = $this->reports->approvalsFor($report->publicId);
        self::assertCount(1, $loaded);
        self::assertSame('rework', $loaded[0]->kind);
        self::assertSame('pending', $loaded[0]->status);

        $decided = $this->decide->execute($this->approverActor(), $report->publicId, 'rework', [
            'decision' => 'approved',
            'rowVersion' => $seeded->approval->rowVersion,
        ]);
        self::assertSame('approved', $decided->approval->status);
        self::assertNull($decided->settlement);
        self::assertSame('assigned', $decided->report->status);
    }

    /** Live rule: a pending credit follows a reassignment. */
    public function test_reassigning_a_report_moves_a_pending_credit_to_the_new_assignee(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'creditTotal' => '80.00',
            'rowVersion' => $report->rowVersion,
        ]);
        $reassigned = $this->assignTo($this->reports, $pending->report, 3, 'sam@tricab.com', 'Sam Reviewer');
        self::assertSame('sam@tricab.com', $reassigned->assignedTo);

        $row = $this->reports->approvalsFor($report->publicId)[0];
        self::assertSame('sam@tricab.com', $row->approver);
        self::assertSame(3, $row->approverUserId);
        self::assertSame('pending', $row->status);

        // The previous assignee can no longer decide it; the new one can.
        try {
            $this->decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
                'decision' => 'approved',
                'rowVersion' => $row->rowVersion,
            ]);
            self::fail('The previous assignee decided a credit that moved away from them.');
        } catch (CustomerContactReportApprovalFailure $failure) {
            self::assertSame(CustomerContactReportApprovalFailure::NOT_APPROVER, $failure->errorCode);
        }
        $decided = $this->decide->execute($this->person(3, 'sam@tricab.com', 'Sam Reviewer'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $row->rowVersion,
        ]);
        self::assertSame('approved', $decided->approval->status);
    }

    public function test_full_chain_closes_the_report_when_the_credit_note_is_uploaded(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'creditTotal' => '1200.00',
            'rowVersion' => $report->rowVersion,
        ]);
        $approved = $this->decide->execute($this->approverActor(), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        self::assertNotNull($approved->settlement);
        self::assertSame('pending', $approved->settlement->status);
        self::assertSame('accounts@tricab.com', $approved->settlement->approver);
        self::assertSame('assigned', $this->reload($report->publicId)->status);

        $this->settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $report->publicId,
            'note.pdf',
            '%PDF-1.4',
            null,
            ['rowVersion' => $approved->settlement->rowVersion, 'note' => 'CN-1'],
            new CustomerContactReportVisibility(false, 3, 'accounts@tricab.com'),
        );

        $closed = $this->reload($report->publicId);
        self::assertSame('closed', $closed->status);
        self::assertSame('Accounts', $closed->closedBy);
        self::assertNotNull($closed->closedAtUtc);
        $kinds = array_map(static fn ($row): string => $row->kind . ':' . $row->status, $this->reports->approvalsFor($report->publicId));
        self::assertSame(['credit:approved', 'settlement:approved'], $kinds);
    }

    public function test_settlement_also_closes_a_completed_report(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $completed = $this->complete->execute($this->approverActor(), $report->publicId, [
            'rowVersion' => $report->rowVersion,
            'assigneeActionTaken' => 'Replaced',
            'assigneeFindings' => 'Faulty',
        ]);
        self::assertSame('completed', $completed->status);

        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'creditTotal' => '20.00',
            'rowVersion' => $completed->rowVersion,
        ]);
        $approved = $this->decide->execute($this->approverActor(), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        self::assertNotNull($approved->settlement);
        $this->settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $report->publicId,
            'note.pdf',
            '%PDF-1.4',
            null,
            ['rowVersion' => $approved->settlement->rowVersion],
            new CustomerContactReportVisibility(false, 3, 'accounts@tricab.com'),
        );

        $closed = $this->reload($report->publicId);
        self::assertSame('closed', $closed->status);
        self::assertSame('Accounts', $closed->closedBy);
    }

    public function test_finance_cannot_upload_a_credit_note_before_the_credit_is_approved(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $accounts = new CustomerContactReportVisibility(true, 3, 'accounts@tricab.com');
        $attempt = fn (string $rowVersion) => $this->settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $report->publicId,
            'note.pdf',
            '%PDF-1.4',
            null,
            ['rowVersion' => $rowVersion],
            $accounts,
        );

        try {
            $attempt($report->rowVersion);
            self::fail('A credit note was accepted with no credit.');
        } catch (CustomerContactReportFileFailure $failure) {
            self::assertSame(CustomerContactReportFileFailure::CREDIT_NOTE_NOT_ALLOWED, $failure->errorCode);
        }

        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'creditTotal' => '20.00',
            'rowVersion' => $report->rowVersion,
        ]);
        try {
            $attempt($pending->approval->rowVersion);
            self::fail('A credit note was accepted for a pending credit.');
        } catch (CustomerContactReportFileFailure $failure) {
            self::assertSame(CustomerContactReportFileFailure::CREDIT_NOTE_NOT_ALLOWED, $failure->errorCode);
        }
        self::assertSame('assigned', $this->reload($report->publicId)->status);
        self::assertSame([], $this->files->objects);
    }

    public function test_a_declined_credit_needs_a_note_and_leaves_the_report_open(): void
    {
        $report = $this->assignTo($this->reports, $this->insert());
        $pending = $this->request->execute($this->raiser(), $report->publicId, 'credit', [
            'creditTotal' => '20.00',
            'rowVersion' => $report->rowVersion,
        ]);
        try {
            $this->decide->execute($this->approverActor(), $report->publicId, 'credit', [
                'decision' => 'declined',
                'rowVersion' => $pending->approval->rowVersion,
            ]);
            self::fail('A decline without a note was accepted.');
        } catch (InvalidCustomerContactReportValue) {
            self::assertSame('pending', $this->reports->approvalsFor($report->publicId)[0]->status);
        }

        $declined = $this->decide->execute($this->approverActor(), $report->publicId, 'credit', [
            'decision' => 'declined',
            'note' => 'Not warranted.',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        self::assertSame('declined', $declined->approval->status);
        self::assertNull($declined->settlement);
        self::assertSame('assigned', $declined->report->status);
    }

    private function reload(string $publicId): CustomerContactReportDetails
    {
        $found = $this->reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        );
        self::assertNotNull($found);

        return $found;
    }

    private function insert(): CustomerContactReportDetails
    {
        return $this->reports->insert(new NewCustomerContactReport(
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

    private function raiser(): AuthenticatedUser
    {
        return $this->person(1, 'thilanh@tricab.com', 'Thilan Lakshitha');
    }

    private function approverActor(): AuthenticatedUser
    {
        return $this->person(2, 'alex@tricab.com', 'Alex Assignee');
    }

    private function person(int $userId, string $email, string $name): AuthenticatedUser
    {
        return new AuthenticatedUser(
            $userId,
            '550e8400-e29b-41d4-a716-446655440000',
            $email,
            $name,
            'active',
            ['EMPLOYEE'],
            ['ACCOUNT.LOGIN', Module::PERMISSION_APPROVE],
        );
    }
}
