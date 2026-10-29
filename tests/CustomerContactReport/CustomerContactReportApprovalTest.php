<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
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
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\RequestCustomerContactReportApprovalService;
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
    use AssignsCustomerContactReports;

    public function test_credit_request_records_the_caller_and_the_assignee_as_approver(): void
    {
        [$request, $reports] = $this->services();
        $report = $this->assigned($reports);

        $outcome = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '80.00',
            'rowVersion' => $report->rowVersion,
        ]);

        self::assertSame('assigned', $outcome->report->status);
        self::assertSame($report->ccNumber, $outcome->report->ccNumber);
        self::assertSame(1, $reports->numberSequenceRows());
        self::assertSame('pending', $outcome->approval->status);
        self::assertSame('credit', $outcome->approval->kind);
        self::assertSame('alex@tricab.com', $outcome->approval->approver);
        self::assertSame(2, $outcome->approval->approverUserId);
        self::assertSame('Alex Assignee', $outcome->approval->approverName);
        self::assertSame('alex@tricab.com', $outcome->approval->approverEmail);
        self::assertSame('thilanh@tricab.com', $outcome->approval->requestedBy);
        self::assertSame(1, $outcome->approval->requestedByUserId);
        self::assertSame('2026-09-30T01:00:00+00:00', $outcome->approval->requestedAtUtc->format('c'));
        self::assertNull($outcome->approval->decidedAtUtc);
        self::assertNotSame('', $outcome->approval->rowVersion);
        self::assertSame('80.00', $outcome->report->creditTotal);
    }

    public function test_a_pending_credit_can_be_sent_again_and_a_decided_one_cannot(): void
    {
        [$request, $reports, $decide] = $this->services('accounts@tricab.com');
        $report = $this->assigned($reports);
        $first = $this->credit($request, $report, '80.00');
        // Reassigning does not touch the pending row; a re-send re-points it at the new assignee.
        $reassigned = $this->assignTo($reports, $first->report, 3, 'sam@tricab.com', 'Sam Reviewer');
        $again = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '90.00',
            'rowVersion' => $reassigned->rowVersion,
        ]);

        self::assertSame($first->approval->approvalId, $again->approval->approvalId);
        self::assertSame('pending', $again->approval->status);
        self::assertSame('sam@tricab.com', $again->approval->approver);
        self::assertNull($again->approval->note);
        self::assertNotSame($first->approval->rowVersion, $again->approval->rowVersion);
        self::assertCount(1, $reports->approvalsFor($report->publicId));

        $decided = $decide->execute($this->person(3, 'sam@tricab.com', 'Sam Reviewer'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $again->approval->rowVersion,
        ]);
        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_PENDING,
            fn () => $request->execute($this->caller(), $report->publicId, 'credit', [
                'creditTotal' => '90.00',
                'rowVersion' => $decided->report->rowVersion,
            ]),
        );
        $stored = $reports->approvalsFor($report->publicId)[0];
        self::assertSame('approved', $stored->status);
        self::assertSame('sam@tricab.com', $stored->approver);
        self::assertSame('assigned', $decided->report->status);
    }

    public function test_a_credit_on_an_unassigned_report_is_refused_and_writes_nothing(): void
    {
        [$request, $reports] = $this->services();
        $report = $this->insert($reports);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::CONFIGURATION,
            fn () => $request->execute($this->caller(), $report->publicId, 'credit', [
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

    public function test_the_assignee_is_the_approver_and_the_raiser_assigned_to_self_is_rejected(): void
    {
        [$request, $reports] = $this->services();
        $allowed = $this->credit($request, $this->assigned($reports), '10.00');
        self::assertSame('alex@tricab.com', $allowed->approval->approver);
        self::assertSame('thilanh@tricab.com', $allowed->approval->requestedBy);

        $own = $this->assignTo($reports, $this->insert($reports), 1, 'thilanh@tricab.com', 'Thilan Lakshitha');
        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $request->execute($this->caller(), $own->publicId, 'credit', [
                'creditTotal' => '100.00',
                'rowVersion' => $own->rowVersion,
            ]),
        );
        self::assertSame([], $reports->approvalsFor($own->publicId));
        self::assertNull($reports->findVisible(
            CustomerContactReportPublicId::fromString($own->publicId),
            new CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        )?->creditTotal);

        // Raised by alex, assigned to the requester: still the requester's own credit.
        $fromAlex = $this->assignTo($reports, $this->insert($reports, 'alex@tricab.com', 2), 1, 'thilanh@tricab.com', 'Thilan Lakshitha');
        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $request->execute($this->caller(), $fromAlex->publicId, 'credit', [
                'creditTotal' => '100.00',
                'rowVersion' => $fromAlex->rowVersion,
            ]),
        );
        self::assertSame([], $reports->approvalsFor($fromAlex->publicId));
    }

    public function test_a_requester_with_approve_cannot_decide_their_own_request(): void
    {
        [, $reports, $decide] = $this->services();
        $report = $this->insert($reports);
        $seeded = $reports->requestApproval(new CustomerContactReportApprovalRequest(
            publicId: $report->publicId,
            expectedReportRowVersion: $report->rowVersion,
            kind: 'credit',
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
            fn () => $decide->execute($this->caller(), $report->publicId, 'credit', [
                'decision' => 'approved',
                'rowVersion' => $seeded->approval->rowVersion,
            ]),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::SELF,
            fn () => $reports->decideApproval(new CustomerContactReportApprovalDecision(
                publicId: $report->publicId,
                kind: 'credit',
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
        [$request, $reports, $decide] = $this->services();
        $pending = $this->credit($request, $this->assigned($reports), '40.00');

        $this->assertFailure(
            CustomerContactReportApprovalFailure::PERMISSION,
            fn () => $decide->execute(
                $this->person(2, 'alex@tricab.com', 'Alex Assignee', ['ACCOUNT.LOGIN']),
                $pending->report->publicId,
                'credit',
                [
                    'decision' => 'approved',
                    'rowVersion' => $pending->approval->rowVersion,
                ],
            ),
        );
        self::assertSame('pending', $reports->approvalsFor($pending->report->publicId)[0]->status);
    }

    public function test_only_the_recorded_approver_can_move_a_pending_approval(): void
    {
        [$request, $reports, $decide] = $this->services('accounts@tricab.com');
        $report = $this->assigned($reports);
        $pending = $this->credit($request, $report, '40.00');

        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_APPROVER,
            fn () => $decide->execute($this->caller(), $report->publicId, 'credit', [
                'decision' => 'approved',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );
        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_APPROVER,
            fn () => $decide->execute($this->person(9, 'reader@tricab.com', 'Reader'), $report->publicId, 'credit', [
                'decision' => 'declined',
                'note' => 'Not my approval.',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );

        $declined = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'declined',
            'note' => 'Send it back.',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        self::assertSame('declined', $declined->approval->status);
        self::assertSame('Send it back.', $declined->approval->note);
        self::assertNotNull($declined->approval->decidedAtUtc);
        self::assertNotSame($pending->approval->rowVersion, $declined->approval->rowVersion);
        self::assertSame('assigned', $declined->report->status);
        self::assertNull($declined->settlement);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::NOT_PENDING,
            fn () => $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
                'decision' => 'approved',
                'rowVersion' => $declined->approval->rowVersion,
            ]),
        );
    }

    public function test_a_declined_credit_needs_a_note(): void
    {
        [$request, $reports, $decide] = $this->services();
        $report = $this->assigned($reports);
        $pending = $this->credit($request, $report, '40.00');

        $this->assertFailure(
            InvalidCustomerContactReportValue::class,
            fn () => $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
                'decision' => 'declined',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);
    }

    public function test_a_stale_approval_row_version_conflicts_and_a_zero_credit_is_accepted(): void
    {
        [$request, $reports, $decide] = $this->services('accounts@tricab.com');
        $report = $this->assigned($reports);
        $pending = $this->credit($request, $report, '40.00');
        $moved = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '45.00',
            'rowVersion' => $pending->report->rowVersion,
        ]);

        $this->assertFailure(
            CustomerContactReportApprovalFailure::CONFLICT,
            fn () => $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
                'decision' => 'approved',
                'rowVersion' => $pending->approval->rowVersion,
            ]),
        );
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[0]->status);
        self::assertSame($moved->approval->rowVersion, $reports->approvalsFor($report->publicId)[0]->rowVersion);

        // A zero total no longer blocks the request.
        $zero = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '0.00',
            'rowVersion' => $moved->report->rowVersion,
        ]);
        self::assertSame('0.00', $zero->report->creditTotal);
        self::assertSame('pending', $zero->approval->status);
    }

    public function test_approving_a_credit_raises_a_pending_settlement_without_changing_status(): void
    {
        [$request, $reports, $decide] = $this->services('accounts@tricab.com');
        $report = $this->assigned($reports);
        $pending = $this->credit($request, $report, '80.00');

        $approved = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'note' => 'Agreed.',
            'rowVersion' => $pending->approval->rowVersion,
        ]);

        self::assertSame('approved', $approved->approval->status);
        self::assertSame('assigned', $approved->report->status);
        self::assertSame('Alex Assignee', $approved->report->creditReviewedBy);
        self::assertSame('2026-09-30', $approved->report->creditReviewedDate);
        self::assertNotNull($approved->settlement);
        self::assertSame('settlement', $approved->settlement->kind);
        self::assertSame('pending', $approved->settlement->status);
        self::assertSame('accounts@tricab.com', $approved->settlement->approver);
        self::assertSame(5, $approved->settlement->approverUserId);
        self::assertSame('alex@tricab.com', $approved->settlement->requestedBy);

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
        [$request, $reports, $decide] = $this->services('');
        $report = $this->assigned($reports);
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
        [$request, $reports, $decide] = $this->services('accounts@tricab.com');
        $report = $this->assigned($reports);
        $pending = $this->credit($request, $report, '40.00');
        $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
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

        $asApprover = CustomerContactReportDtoSerializer::detail($pending->report, false, [$pending->approval], 2, 'alex@tricab.com');
        self::assertArrayHasKey('creditTotal', $asApprover);
        self::assertSame('credit', $this->record($asApprover['approvals'] ?? null)[0]['kind'] ?? null);
        $hidden = CustomerContactReportDtoSerializer::detail($pending->report, false, [$pending->approval], 9, 'reader@tricab.com');
        self::assertArrayNotHasKey('creditTotal', $hidden);
        self::assertArrayNotHasKey('actionsTaken', $hidden);
    }

    public function test_settlement_unknown_kinds_and_rework_are_rejected_on_request(): void
    {
        [$request, $reports] = $this->services();
        $report = $this->assigned($reports);

        foreach (['settlement', 'rework', 'nope'] as $kind) {
            $this->assertFailure(
                InvalidCustomerContactReportValue::class,
                fn () => $request->execute($this->caller(), $report->publicId, $kind, [
                    'rowVersion' => $report->rowVersion,
                ]),
            );
        }
        self::assertSame([], $reports->approvalsFor($report->publicId));
    }

    /**
     * @return array{0: RequestCustomerContactReportApprovalService, 1: InMemoryCustomerContactReportRepository, 2: DecideCustomerContactReportApprovalService}
     */
    private function services(string $accountsLogin = ''): array
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
            new RequestCustomerContactReportApprovalService($reports, $clock),
            $reports,
            new DecideCustomerContactReportApprovalService(
                $reports,
                $staff,
                $clock,
                new CustomerContactReportAccountsLogin($accountsLogin),
            ),
        ];
    }

    private function assigned(InMemoryCustomerContactReportRepository $reports): CustomerContactReportDetails
    {
        return $this->assignTo($reports, $this->insert($reports));
    }

    private function credit(
        RequestCustomerContactReportApprovalService $request,
        CustomerContactReportDetails $report,
        string $total,
        ?string $valueExGst = null,
    ): CustomerContactReportApprovalOutcome {
        $body = [
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
