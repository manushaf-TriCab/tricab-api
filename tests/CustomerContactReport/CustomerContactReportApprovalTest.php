<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
use TriCab\Api\CustomerContactReport\Identity\UnavailableCustomerContactReportReportingLine;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\Http\Action\CustomerContactReport\CustomerContactReportDtoSerializer;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalDecision;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\RequestCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CustomerContactReportApprovalTest extends TestCase
{
    public function test_rework_request_records_the_caller_and_the_resolved_approver(): void
    {
        [$request, $reports] = $this->requestService(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);

        $outcome = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);

        self::assertSame('submitted', $outcome->report->status);
        self::assertSame($report->ccNumber, $outcome->report->ccNumber);
        self::assertSame(0, $reports->numberSequenceRows());
        self::assertSame('pending', $outcome->approval->status);
        self::assertSame('rework', $outcome->approval->kind);
        self::assertSame('alex@tricab.com', $outcome->approval->approver);
        self::assertSame(2, $outcome->approval->approverUserId);
        self::assertSame('Alex Assignee', $outcome->approval->approverName);
        self::assertSame('alex@tricab.com', $outcome->approval->approverEmail);
        self::assertSame('thilanh@tricab.com', $outcome->approval->requestedBy);
        self::assertSame(1, $outcome->approval->requestedByUserId);
        self::assertSame('2026-09-30T01:00:00+00:00', $outcome->approval->requestedAtUtc->format('c'));
        self::assertNull($outcome->approval->decidedAtUtc);
        self::assertNotSame('', $outcome->approval->rowVersion);
    }

    public function test_a_pending_request_can_be_sent_again_and_a_decided_one_cannot(): void
    {
        [$request, $reports, $decide] = $this->services(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);
        $first = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);
        $again = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'sam@tricab.com',
            'rowVersion' => $first->report->rowVersion,
        ]);

        self::assertSame($first->approval->approvalId, $again->approval->approvalId);
        self::assertSame('pending', $again->approval->status);
        self::assertSame('sam@tricab.com', $again->approval->approver);
        self::assertNull($again->approval->note);
        self::assertNotSame($first->approval->rowVersion, $again->approval->rowVersion);
        self::assertCount(1, $reports->approvalsFor($report->publicId));

        $decided = $decide->execute($this->person(3, 'sam@tricab.com', 'Sam Reviewer'), $report->publicId, 'rework', [
            'decision' => 'approved',
            'rowVersion' => $again->approval->rowVersion,
        ]);
        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_PENDING,
            fn () => $request->execute($this->caller(), $report->publicId, 'rework', [
                'approver' => 'alex@tricab.com',
                'rowVersion' => $decided->report->rowVersion,
            ]),
        );
        $stored = $reports->approvalsFor($report->publicId)[0];
        self::assertSame('approved', $stored->status);
        self::assertSame('sam@tricab.com', $stored->approver);
        self::assertSame('submitted', $decided->report->status);
    }

    public function test_credit_request_fails_closed_when_reporting_lines_are_unavailable(): void
    {
        [$request, $reports] = $this->requestService(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::CONFIGURATION,
            fn () => $request->execute($this->caller(), $report->publicId, 'credit', [
                'approver' => 'alex@tricab.com',
                'creditTotal' => '1000.01',
                'rowVersion' => $report->rowVersion,
            ]),
        );
        self::assertSame([], $reports->approvalsFor($report->publicId));
        self::assertNull($reports->findVisible(
            CustomerContactReportPublicId::fromString($report->publicId),
            new CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        )?->creditTotal);
    }

    public function test_credit_escalation_uses_the_raisers_manager_and_climbs_only_above_1000(): void
    {
        $alex = new ResolvedCustomerContactAssignee(2, 'alex@tricab.com', 'Alex Assignee', 'alex@tricab.com');
        $sam = new ResolvedCustomerContactAssignee(3, 'sam@tricab.com', 'Sam Reviewer', 'sam@tricab.com');
        $line = new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => $alex,
            'alex@tricab.com' => $sam,
        ]);
        [$request, $reports] = $this->requestService($line);

        $below = $this->credit($request, $this->insert($reports), '999.99');
        $exact = $this->credit($request, $this->insert($reports), '1000.00');
        $above = $this->credit($request, $this->insert($reports), '1000.01', '944.14');

        self::assertSame('alex@tricab.com', $below->approval->approver);
        self::assertSame('alex@tricab.com', $exact->approval->approver);
        self::assertSame('sam@tricab.com', $above->approval->approver);
        self::assertSame('1000.01', $above->report->creditTotal);
        self::assertSame('944.14', $above->report->valueExGst);
        self::assertSame('submitted', $above->report->status);
        self::assertSame(0, $reports->numberSequenceRows());

        $directOnly = new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => $alex,
        ]);
        [$limited, $limitedReports] = $this->requestService($directOnly);
        $stays = $this->credit($limited, $this->insert($limitedReports), '1000.01');
        self::assertSame('alex@tricab.com', $stays->approval->approver);
    }

    public function test_rework_for_another_approver_is_allowed_and_self_selection_is_rejected(): void
    {
        [$request, $reports] = $this->requestService(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);

        $allowed = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);
        self::assertSame('alex@tricab.com', $allowed->approval->approver);
        self::assertSame('thilanh@tricab.com', $allowed->approval->requestedBy);

        $own = $this->insert($reports);
        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $request->execute($this->caller(), $own->publicId, 'rework', [
                'approver' => 'thilanh@tricab.com',
                'rowVersion' => $own->rowVersion,
            ]),
        );
        self::assertSame([], $reports->approvalsFor($own->publicId));
    }

    public function test_credit_that_resolves_to_the_requester_is_rejected_without_a_further_climb(): void
    {
        $requester = new ResolvedCustomerContactAssignee(1, 'thilanh@tricab.com', 'Thilan Lakshitha', 'thilanh@tricab.com');
        $sam = new ResolvedCustomerContactAssignee(3, 'sam@tricab.com', 'Sam Reviewer', 'sam@tricab.com');
        $line = new FixedCustomerContactReportReportingLine([
            'alex@tricab.com' => $requester,
            'thilanh@tricab.com' => $sam,
        ]);
        [$request, $reports] = $this->requestService($line);
        $report = $this->insert($reports, 'alex@tricab.com', 2);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $request->execute($this->caller(), $report->publicId, 'credit', [
                'creditTotal' => '100.00',
                'rowVersion' => $report->rowVersion,
            ]),
        );
        self::assertSame([], $reports->approvalsFor($report->publicId));
        self::assertNull($reports->findVisible(
            CustomerContactReportPublicId::fromString($report->publicId),
            new CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        )?->creditTotal);

        $climbedOntoRequester = new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => new ResolvedCustomerContactAssignee(2, 'alex@tricab.com', 'Alex Assignee', 'alex@tricab.com'),
            'alex@tricab.com' => $requester,
        ]);
        [$climbed, $climbedReports] = $this->requestService($climbedOntoRequester);
        $above = $this->insert($climbedReports);
        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $climbed->execute($this->caller(), $above->publicId, 'credit', [
                'creditTotal' => '1000.01',
                'rowVersion' => $above->rowVersion,
            ]),
        );
        self::assertSame([], $climbedReports->approvalsFor($above->publicId));
    }

    public function test_a_requester_with_approve_cannot_decide_their_own_request(): void
    {
        [, $reports, $decide] = $this->services(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);
        $seeded = $reports->requestApproval(new CustomerContactReportApprovalRequest(
            publicId: $report->publicId,
            expectedReportRowVersion: $report->rowVersion,
            kind: 'rework',
            approverLogin: 'thilanh@tricab.com',
            approverUserId: 1,
            approverName: 'Thilan Lakshitha',
            approverEmail: 'thilanh@tricab.com',
            requestedBy: 'thilanh@tricab.com',
            requestedByUserId: 1,
            requestedAtUtc: new DateTimeImmutable('2026-09-30 01:00:00', new DateTimeZone('UTC')),
            writeCreditFigures: false,
            valueExGst: null,
            restockingAmount: null,
            subTotal: null,
            gst: null,
            creditTotal: null,
        ));

        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $decide->execute($this->caller(), $report->publicId, 'rework', [
                'decision' => 'approved',
                'rowVersion' => $seeded->approval->rowVersion,
            ]),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $reports->decideApproval(new CustomerContactReportApprovalDecision(
                publicId: $report->publicId,
                kind: 'rework',
                expectedRowVersion: $seeded->approval->rowVersion,
                decision: 'approved',
                note: null,
                actorUserId: 1,
                actorEmail: 'thilanh@tricab.com',
                decidedAtUtc: new DateTimeImmutable('2026-09-30 01:00:00', new DateTimeZone('UTC')),
                settlementApproverUserId: null,
                settlementApproverLogin: null,
                settlementApproverName: null,
                settlementApproverEmail: null,
            )),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);
    }

    public function test_a_recorded_approver_without_approve_is_rejected(): void
    {
        [$request, $reports, $decide] = $this->services(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);
        $pending = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::PERMISSION,
            fn () => $decide->execute(
                $this->person(2, 'alex@tricab.com', 'Alex Assignee', ['ACCOUNT.LOGIN']),
                $report->publicId,
                'rework',
                [
                    'decision' => 'approved',
                    'rowVersion' => $pending->approval->rowVersion,
                ],
            ),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);
    }

    public function test_a_broken_reporting_chain_can_fall_back_to_a_picked_approver(): void
    {
        [$request, $reports] = $this->requestService(new FixedCustomerContactReportReportingLine([]));
        $report = $this->insert($reports);
        $picked = $request->execute($this->caller(), $report->publicId, 'credit', [
            'approver' => 'pat@tricab.com',
            'creditTotal' => '50.00',
            'rowVersion' => $report->rowVersion,
        ]);
        self::assertSame('pat@tricab.com', $picked->approval->approver);
        self::assertSame(4, $picked->approval->approverUserId);

        $unrouted = $this->insert($reports);
        $this->assertFailure(
            CustomerContactReportApprovalFailure::CONFIGURATION,
            fn () => $request->execute($this->caller(), $unrouted->publicId, 'credit', [
                'creditTotal' => '50.00',
                'rowVersion' => $unrouted->rowVersion,
            ]),
        );
    }

    public function test_only_the_recorded_approver_can_move_a_pending_approval(): void
    {
        [$request, $reports, $decide] = $this->services(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);
        $pending = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_APPROVER,
            fn () => $decide->execute($this->caller(), $report->publicId, 'rework', [
                'decision' => 'approved',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );
        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_APPROVER,
            fn () => $decide->execute($this->person(9, 'reader@tricab.com', 'Reader'), $report->publicId, 'rework', [
                'decision' => 'declined',
                'note' => 'Not my approval.',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );

        $declined = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'rework', [
            'decision' => 'declined',
            'note' => 'Send it back.',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        self::assertSame('declined', $declined->approval->status);
        self::assertSame('Send it back.', $declined->approval->note);
        self::assertNotNull($declined->approval->decidedAtUtc);
        self::assertNotSame($pending->approval->rowVersion, $declined->approval->rowVersion);
        self::assertSame('submitted', $declined->report->status);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_PENDING,
            fn () => $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'rework', [
                'decision' => 'approved',
                'rowVersion' => $declined->approval->rowVersion,
            ]),
        );
    }

    public function test_a_stale_approval_row_version_conflicts_and_zero_credit_is_rejected(): void
    {
        [$request, $reports, $decide] = $this->services(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);
        $pending = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);
        $moved = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'sam@tricab.com',
            'rowVersion' => $pending->report->rowVersion,
        ]);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::CONFLICT,
            fn () => $decide->execute($this->person(3, 'sam@tricab.com', 'Sam Reviewer'), $report->publicId, 'rework', [
                'decision' => 'approved',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);
        self::assertSame($moved->approval->rowVersion, $reports->approvalsFor($report->publicId)[0]->rowVersion);

        try {
            $request->execute($this->caller(), $report->publicId, 'credit', [
                'creditTotal' => '0.00',
                'rowVersion' => $moved->report->rowVersion,
            ]);
            self::fail('A zero credit was accepted.');
        } catch (InvalidCustomerContactReportValue) {
            self::assertSame([], array_values(array_filter(
                $reports->approvalsFor($report->publicId),
                static fn ($approval): bool => $approval->kind === 'credit',
            )));
        }
    }

    public function test_approving_a_credit_raises_a_pending_settlement_without_changing_status(): void
    {
        $alex = new ResolvedCustomerContactAssignee(2, 'alex@tricab.com', 'Alex Assignee', 'alex@tricab.com');
        [$request, $reports, $decide] = $this->services(new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => $alex,
        ]), 'accounts@tricab.com');
        $report = $this->insert($reports);
        $pending = $this->credit($request, $report, '80.00');

        $approved = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'note' => 'Agreed.',
            'rowVersion' => $pending->approval->rowVersion,
        ]);

        self::assertSame('approved', $approved->approval->status);
        self::assertSame('submitted', $approved->report->status);
        self::assertSame('Alex Assignee', $approved->report->creditReviewedBy);
        self::assertSame('2026-09-30', $approved->report->creditReviewedDate);
        self::assertNotNull($approved->settlement);
        self::assertSame('settlement', $approved->settlement->kind);
        self::assertSame('pending', $approved->settlement->status);
        self::assertSame('accounts@tricab.com', $approved->settlement->approver);
        self::assertSame(5, $approved->settlement->approverUserId);
        self::assertSame('alex@tricab.com', $approved->settlement->requestedBy);
        self::assertSame('submitted', $approved->report->status);

        $this->assertFailure(
            InvalidCustomerContactReportValue::class,
            fn () => $decide->execute($this->person(5, 'accounts@tricab.com', 'Accounts'), $report->publicId, 'settlement', [
                'decision' => 'approved',
                'rowVersion' => $approved->settlement->rowVersion,
            ]),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[1]->status);
    }

    public function test_credit_approval_is_refused_when_accounts_is_not_configured(): void
    {
        $alex = new ResolvedCustomerContactAssignee(2, 'alex@tricab.com', 'Alex Assignee', 'alex@tricab.com');
        [$request, $reports, $decide] = $this->services(new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => $alex,
        ]), '');
        $report = $this->insert($reports);
        $pending = $this->credit($request, $report, '80.00');

        $this->assertFailure(
            CustomerContactReportApprovalFailure::CONFIGURATION,
            fn () => $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
                'decision' => 'approved',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );
        $stored = $reports->approvalsFor($report->publicId);
        self::assertCount(1, $stored);
        self::assertSame('pending', $stored[0]->status);
        self::assertSame('credit', $stored[0]->kind);
    }

    public function test_approver_visibility_remains_after_the_decision(): void
    {
        [$request, $reports, $decide] = $this->services(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);
        $pending = $request->execute($this->caller(), $report->publicId, 'rework', [
            'approver' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);
        $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'rework', [
            'decision' => 'approved',
            'rowVersion' => $pending->approval->rowVersion,
        ]);

        $id = CustomerContactReportPublicId::fromString($report->publicId);
        self::assertNotNull($reports->findVisible($id, new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com')));
        self::assertNotNull($reports->findVisible($id, new CustomerContactReportVisibility(false, 2, 'alex@tricab.com')));
        self::assertNull($reports->findVisible($id, new CustomerContactReportVisibility(false, 9, 'reader@tricab.com')));
        self::assertNotNull($reports->findVisible($id, new CustomerContactReportVisibility(true, 9, 'reader@tricab.com')));

        $assigned = $reports->seedCopy($report, 90, 'other@tricab.com', 'assigned', '2609-001M-SLS', 'branch', 'SLS', 'sam@tricab.com');
        $assignedId = CustomerContactReportPublicId::fromString($assigned->publicId);
        self::assertNotNull($reports->findVisible($assignedId, new CustomerContactReportVisibility(false, 3, 'sam@tricab.com')));
        self::assertNull($reports->findVisible($assignedId, new CustomerContactReportVisibility(false, 9, 'reader@tricab.com')));

        $hidden = CustomerContactReportDtoSerializer::detail($pending->report, false, [$pending->approval], 2, 'alex@tricab.com');
        self::assertArrayNotHasKey('creditTotal', $hidden);
        self::assertArrayNotHasKey('actionsTaken', $hidden);
        self::assertSame('rework', $this->record($hidden['approvals'] ?? null)[0]['kind'] ?? null);
    }

    public function test_unknown_approver_and_settlement_request_are_rejected(): void
    {
        [$request, $reports] = $this->requestService(new UnavailableCustomerContactReportReportingLine());
        $report = $this->insert($reports);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::APPROVER_NOT_FOUND,
            fn () => $request->execute($this->caller(), $report->publicId, 'rework', [
                'approver' => 'missing@tricab.com',
                'rowVersion' => $report->rowVersion,
            ]),
        );
        $this->assertFailure(
            InvalidCustomerContactReportValue::class,
            fn () => $request->execute($this->caller(), $report->publicId, 'settlement', [
                'rowVersion' => $report->rowVersion,
            ]),
        );
        $this->assertFailure(
            InvalidCustomerContactReportValue::class,
            fn () => $request->execute($this->caller(), $report->publicId, 'nope', [
                'approver' => 'alex@tricab.com',
                'rowVersion' => $report->rowVersion,
            ]),
        );
    }

    /**
     * @return array{0: RequestCustomerContactReportApprovalService, 1: InMemoryCustomerContactReportRepository}
     */
    private function requestService(CustomerContactReportReportingLine $line): array
    {
        [$request, $reports] = $this->services($line);

        return [$request, $reports];
    }

    /**
     * @return array{0: RequestCustomerContactReportApprovalService, 1: InMemoryCustomerContactReportRepository, 2: DecideCustomerContactReportApprovalService}
     */
    private function services(CustomerContactReportReportingLine $line, string $accountsLogin = ''): array
    {
        $users = new InMemoryApiUserRepository();
        $this->register($users, 'alex@tricab.com', 'Alex Assignee');
        $this->register($users, 'sam@tricab.com', 'Sam Reviewer');
        $this->register($users, 'pat@tricab.com', 'Pat Picked');
        $this->register($users, 'accounts@tricab.com', 'Accounts');
        $reports = new InMemoryCustomerContactReportRepository();
        $staff = new IdentityCustomerContactReportAssigneeDirectory($users);
        $clock = FixedClock::utc('2026-09-30 01:00:00');

        return [
            new RequestCustomerContactReportApprovalService($reports, $staff, $line, $clock),
            $reports,
            new DecideCustomerContactReportApprovalService(
                $reports,
                $staff,
                $clock,
                new CustomerContactReportAccountsLogin($accountsLogin),
            ),
        ];
    }

    private function credit(
        RequestCustomerContactReportApprovalService $request,
        CustomerContactReportDetails $report,
        string $total,
        ?string $valueExGst = null,
    ): CustomerContactReportApprovalOutcome {
        $body = [
            'approver' => 'pat@tricab.com',
            'creditTotal' => $total,
            'rowVersion' => $report->rowVersion,
        ];
        if ($valueExGst !== null) {
            $body['valueExGst'] = $valueExGst;
        }

        return $request->execute($this->caller(), $report->publicId, 'credit', $body);
    }

    private function insert(
        InMemoryCustomerContactReportRepository $reports,
        string $createdByEmail = 'thilanh@tricab.com',
        int $createdByUserId = 1,
    ): CustomerContactReportDetails {
        return $reports->insert(new NewCustomerContactReport(
            publicId: CustomerContactReportPublicId::generate()->toString(),
            createdByUserId: $createdByUserId,
            createdByEmail: $createdByEmail,
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

    private function register(InMemoryApiUserRepository $users, string $email, string $name): void
    {
        $users->register(
            EmailAddress::fromString($email),
            $name,
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-' . $email)),
        );
    }

    private function caller(): AuthenticatedUser
    {
        return $this->person(1, 'thilanh@tricab.com', 'Thilan Lakshitha');
    }

    /**
     * @param list<string>|null $permissions
     */
    private function person(int $userId, string $email, string $name, ?array $permissions = null): AuthenticatedUser
    {
        return new AuthenticatedUser(
            $userId,
            '550e8400-e29b-41d4-a716-446655440000',
            $email,
            $name,
            'active',
            ['EMPLOYEE'],
            $permissions ?? ['ACCOUNT.LOGIN', Module::PERMISSION_APPROVE],
        );
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertFailure(string $expected, callable $action): void
    {
        try {
            $action();
            self::fail('Expected ' . $expected . '.');
        } catch (CustomerContactReportApprovalFailure $failure) {
            self::assertSame($expected, $failure->errorCode);
        } catch (InvalidCustomerContactReportValue $failure) {
            self::assertSame(InvalidCustomerContactReportValue::class, $expected);
            self::assertNotSame('', $failure->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function record(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a list.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                self::fail('Expected an object.');
            }
            $record = [];
            foreach ($item as $key => $field) {
                if (!is_string($key)) {
                    self::fail('Expected string keys.');
                }
                $record[$key] = $field;
            }
            $items[] = $record;
        }

        return $items;
    }
}
