<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\CustomerContactReport\Email\CustomerContactReportEmailTemplate;
use TriCab\Api\CustomerContactReport\Email\GraphCustomerContactReportNotifier;
use TriCab\Api\CustomerContactReport\Email\InMemoryCustomerContactReportNotifier;
use TriCab\Api\CustomerContactReport\Identity\GraphCustomerContactReportReportingLine;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\CustomerContactReport\Storage\InMemoryCustomerContactReportFileStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotice;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotificationSettings;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Service\AssignCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CloseCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportSettlementService;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactReportBlobWrite;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactReportNotifications;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\ReopenCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\RequestCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CustomerContactReportNotificationTest extends TestCase
{
    public function test_workflow_mail_follows_the_legacy_recipients_and_skips_reopen(): void
    {
        $mail = new InMemoryCustomerContactReportNotifier();
        [$reports, $notifications, $staff] = $this->notifications($mail);
        $clock = FixedClock::utc('2026-09-30 01:00:00');
        $create = new \TriCab\Core\CustomerContactReport\Application\Service\CreateCustomerContactReportService($reports, $clock, $notifications);
        $assign = new AssignCustomerContactReportService($reports, $staff, $clock, $notifications);
        $complete = new CompleteCustomerContactReportService($reports, $clock, $notifications);
        $close = new CloseCustomerContactReportService($reports, $clock, $notifications);
        $reopen = new ReopenCustomerContactReportService($reports, $clock);

        $report = $create->execute($this->person(1, 'thilanh@tricab.com', 'Thilan <script>'), [
            'reportDate' => '2026-09-30',
            'location' => 'M',
            'customerCode' => 'CUST1',
            'customerContact' => 'Ada',
            'productCode' => 'CAB-1',
            'description' => 'Cable <failed>',
        ]);
        self::assertSame(['submitted_receipt', 'submitted_coordinator'], $this->events($mail));
        self::assertSame(['thilanh@tricab.com'], $mail->sent[0]->recipients);
        self::assertNull($mail->sent[0]->linkUrl);
        self::assertSame(['quality@tricab.com'], $mail->sent[1]->recipients);
        self::assertStringContainsString('/customer-contacts/' . $report->publicId, (string) $mail->sent[1]->linkUrl);
        self::assertStringNotContainsString('memory://', $mail->sent[1]->intro);
        self::assertSame('Cable <failed>', $mail->sent[0]->fields['Problem']);
        self::assertArrayNotHasKey('ICAR signature', $mail->sent[0]->fields);

        $assigned = $assign->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, [
            'route' => 'branch',
            'errorCode' => 'SLS',
            'assignedTo' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);
        self::assertSame('assigned', $this->events($mail)[2]);
        self::assertSame('numbered', $this->events($mail)[3]);
        self::assertSame(['alex@tricab.com'], $mail->sent[2]->recipients);
        self::assertSame(['thilanh@tricab.com'], $mail->sent[3]->recipients);
        self::assertSame($assigned->ccNumber, $mail->sent[2]->fields['CC number']);
        self::assertNotNull($mail->sent[2]->queueUrl);

        $again = $assign->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, [
            'route' => 'branch',
            'errorCode' => 'SLS',
            'assignedTo' => 'sam@tricab.com',
            'rowVersion' => $assigned->rowVersion,
        ]);
        self::assertSame(['assigned'], array_slice($this->events($mail), 4, 1));
        self::assertSame(['sam@tricab.com'], $mail->sent[4]->recipients);

        try {
            $assign->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, [
                'route' => 'branch',
                'errorCode' => 'SLS',
                'assignedTo' => 'alex@tricab.com',
                'rowVersion' => $assigned->rowVersion,
            ]);
            self::fail('A stale assignment was accepted.');
        } catch (CustomerContactReportConflict) {
            self::assertCount(5, $mail->sent);
        }

        $completed = $complete->execute($this->person(3, 'sam@tricab.com', 'Sam Reviewer'), $report->publicId, [
            'assigneeActionTaken' => 'Replaced the lug',
            'assigneeFindings' => 'Loose crimp',
            'rowVersion' => $again->rowVersion,
        ]);
        self::assertSame('completed', $this->events($mail)[5]);
        self::assertSame(['quality@tricab.com'], $mail->sent[5]->recipients);
        self::assertSame('Replaced the lug', $mail->sent[5]->fields['Action taken']);

        $closed = $close->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, [
            'rowVersion' => $completed->rowVersion,
        ]);
        self::assertSame('closed', $this->events($mail)[6]);
        self::assertSame(['thilanh@tricab.com'], $mail->sent[6]->recipients);
        self::assertSame('Replaced the lug', $mail->sent[6]->fields['Outcome']);

        $before = $mail->attempts;
        $reopen->execute($closed->publicId, ['rowVersion' => $closed->rowVersion]);
        self::assertSame($before, $mail->attempts);
    }

    public function test_a_failed_notice_leaves_the_assignment_and_the_credit_committed(): void
    {
        $mail = new InMemoryCustomerContactReportNotifier();
        $mail->fail = true;
        [$reports, $notifications, $staff] = $this->notifications($mail);
        $clock = FixedClock::utc('2026-09-30 01:00:00');
        $assign = new AssignCustomerContactReportService($reports, $staff, $clock, $notifications);
        $report = $this->insert($reports);
        $assigned = $assign->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, [
            'route' => 'branch',
            'errorCode' => 'SLS',
            'assignedTo' => 'alex@tricab.com',
            'rowVersion' => $report->rowVersion,
        ]);

        self::assertSame('assigned', $assigned->status);
        self::assertNotNull($assigned->ccNumber);
        self::assertSame([], $mail->sent);
        self::assertGreaterThan(0, $mail->attempts);
        self::assertSame(1, $reports->numberSequenceRows());

        $line = new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => new \TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee(
                2,
                'alex@tricab.com',
                'Alex Assignee',
                'alex@tricab.com',
            ),
        ]);
        $request = new RequestCustomerContactReportApprovalService($reports, $staff, $line, $clock, $notifications);
        $decide = new DecideCustomerContactReportApprovalService(
            $reports,
            $staff,
            $clock,
            new CustomerContactReportAccountsLogin('accounts@tricab.com'),
            $notifications,
        );
        $pending = $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, 'credit', [
            'creditTotal' => '1000.01',
            'rowVersion' => $assigned->rowVersion,
        ]);
        $decided = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $pending->approval->rowVersion,
        ]);

        self::assertSame('approved', $decided->approval->status);
        self::assertNotNull($decided->settlement);
        self::assertSame('pending', $decided->settlement->status);
        self::assertSame('assigned', $reports->findVisible(
            CustomerContactReportPublicId::fromString($report->publicId),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        )?->status);
    }

    public function test_credit_note_mail_failure_keeps_the_file_and_the_settlement(): void
    {
        $mail = new InMemoryCustomerContactReportNotifier();
        [$reports, $notifications, $staff] = $this->notifications($mail);
        $clock = FixedClock::utc('2026-09-30 01:00:00');
        $line = new FixedCustomerContactReportReportingLine([
            'thilanh@tricab.com' => new \TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee(
                2,
                'alex@tricab.com',
                'Alex Assignee',
                'alex@tricab.com',
            ),
        ]);
        $request = new RequestCustomerContactReportApprovalService($reports, $staff, $line, $clock, $notifications);
        $decide = new DecideCustomerContactReportApprovalService(
            $reports,
            $staff,
            $clock,
            new CustomerContactReportAccountsLogin('accounts@tricab.com'),
            $notifications,
        );
        $report = $this->insert($reports);
        $pending = $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, 'credit', [
            'creditTotal' => '40.00',
            'rowVersion' => $report->rowVersion,
        ]);
        self::assertSame('approval_requested', $mail->sent[0]->event);
        self::assertSame(['alex@tricab.com'], $mail->sent[0]->recipients);
        self::assertSame('$40.00', $mail->sent[0]->fields['Credit Total']);

        $decided = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'declined',
            'note' => 'Not <approved>',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        $decline = $this->latestNotice($mail);
        self::assertSame('approval_decided', $decline->event);
        self::assertSame('Not <approved>', $decline->fields['Reason']);
        self::assertNotContains('accounts@tricab.com', $decline->recipients);
        self::assertNull($decided->settlement);

        $second = $this->insert($reports);
        $credit = $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $second->publicId, 'credit', [
            'creditTotal' => '40.00',
            'rowVersion' => $second->rowVersion,
        ]);
        $approved = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $second->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $credit->approval->rowVersion,
        ]);
        $decision = $this->latestNotice($mail);
        self::assertContains('accounts-copy@tricab.com', $decision->recipients);
        self::assertNotNull($approved->settlement);

        $mail->fail = true;
        $before = $mail->attempts;
        $files = new InMemoryCustomerContactReportFileStore();
        $settlement = new CompleteCustomerContactReportSettlementService(
            $reports,
            new CustomerContactReportBlobWrite($files),
            $clock,
            $notifications,
        );
        $stored = $settlement->execute(
            $this->person(4, 'accounts@tricab.com', 'Accounts'),
            $second->publicId,
            'note.pdf',
            '%PDF-1.4',
            'application/pdf',
            ['rowVersion' => $approved->settlement->rowVersion],
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 4, 'accounts@tricab.com'),
        );
        self::assertSame('credit_note', $stored->kind);
        self::assertNotSame([], $files->objects);
        self::assertGreaterThan($before, $mail->attempts);
        $rows = $reports->approvalsFor($second->publicId);
        self::assertSame('approved', $rows[1]->status);
        self::assertSame('submitted', $reports->findVisible(
            CustomerContactReportPublicId::fromString($second->publicId),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        )?->status);
    }

    public function test_a_failed_credit_request_sends_nothing(): void
    {
        $mail = new InMemoryCustomerContactReportNotifier();
        [$reports, $notifications, $staff] = $this->notifications($mail);
        $request = new RequestCustomerContactReportApprovalService(
            $reports,
            $staff,
            new \TriCab\Api\CustomerContactReport\Identity\UnavailableCustomerContactReportReportingLine(),
            FixedClock::utc('2026-09-30 01:00:00'),
            $notifications,
        );
        $report = $this->insert($reports);
        try {
            $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $report->publicId, 'credit', [
                'approver' => 'alex@tricab.com',
                'creditTotal' => '10.00',
                'rowVersion' => $report->rowVersion,
            ]);
            self::fail('An unavailable reporting line accepted a credit.');
        } catch (CustomerContactReportApprovalFailure $failure) {
            self::assertSame(CustomerContactReportApprovalFailure::CONFIGURATION, $failure->errorCode);
            self::assertSame(0, $mail->attempts);
        }
    }

    public function test_graph_manager_lookup_normalises_to_active_staff_and_keeps_the_threshold(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            $this->json(200, ['access_token' => 'token', 'expires_in' => 3600]),
            $this->json(200, ['value' => [['id' => 'raiser', 'accountEnabled' => true]]]),
            $this->json(200, ['mail' => 'alex@tricab.com', 'accountEnabled' => true]),
        ];
        [$reports, , $staff] = $this->notifications(new InMemoryCustomerContactReportNotifier());
        $line = new GraphCustomerContactReportReportingLine($http, 'tenant', 'client', 'secret', $staff);
        $request = new RequestCustomerContactReportApprovalService(
            $reports,
            $staff,
            $line,
            FixedClock::utc('2026-09-30 01:00:00'),
        );
        $exactReport = $this->insert($reports);
        $exact = $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $exactReport->publicId, 'credit', [
            'creditTotal' => '1000.00',
            'rowVersion' => $exactReport->rowVersion,
        ]);
        self::assertSame('alex@tricab.com', $exact->approval->approver);
        self::assertCount(1, array_filter(
            $http->calls,
            static fn (array $call): bool => str_contains($call['url'], '/manager'),
        ));

        $http->queue = [
            $this->json(200, ['value' => [['id' => 'raiser', 'accountEnabled' => true]]]),
            $this->json(200, ['mail' => 'alex@tricab.com', 'userPrincipalName' => 'alex@tricab.com', 'accountEnabled' => true]),
            $this->json(200, ['value' => [['id' => 'alex', 'accountEnabled' => true]]]),
            $this->json(200, ['mail' => 'sam@tricab.com', 'accountEnabled' => true]),
        ];
        $aboveReport = $this->insert($reports);
        $above = $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $aboveReport->publicId, 'credit', [
            'creditTotal' => '1000.01',
            'rowVersion' => $aboveReport->rowVersion,
        ]);
        self::assertSame('sam@tricab.com', $above->approval->approver);
    }

    public function test_a_missing_or_unresolved_manager_uses_only_an_explicit_fallback(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            $this->json(200, ['access_token' => 'token', 'expires_in' => 3600]),
            $this->json(200, ['value' => [['id' => 'raiser', 'accountEnabled' => true]]]),
            $this->json(404, ['error' => 'not found']),
        ];
        [$reports, , $staff] = $this->notifications(new InMemoryCustomerContactReportNotifier());
        $line = new GraphCustomerContactReportReportingLine($http, 'tenant', 'client', 'secret', $staff);
        self::assertTrue($line->isAvailable());
        $request = new RequestCustomerContactReportApprovalService(
            $reports,
            $staff,
            $line,
            FixedClock::utc('2026-09-30 01:00:00'),
        );
        $pickedReport = $this->insert($reports);
        $picked = $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $pickedReport->publicId, 'credit', [
            'approver' => 'pat@tricab.com',
            'creditTotal' => '12.00',
            'rowVersion' => $pickedReport->rowVersion,
        ]);
        self::assertSame('pat@tricab.com', $picked->approval->approver);

        $http->queue = [
            $this->json(200, ['value' => []]),
        ];
        $unrouted = $this->insert($reports);
        try {
            $request->execute($this->person(1, 'thilanh@tricab.com', 'Thilan'), $unrouted->publicId, 'credit', [
                'creditTotal' => '12.00',
                'rowVersion' => $unrouted->rowVersion,
            ]);
            self::fail('A missing manager was invented.');
        } catch (CustomerContactReportApprovalFailure $failure) {
            self::assertSame(CustomerContactReportApprovalFailure::CONFIGURATION, $failure->errorCode);
        }

        $disabled = new GraphCustomerContactReportReportingLine(new FakeJsonHttpClient(), '', '', '', $staff);
        self::assertFalse($disabled->isAvailable());
        self::assertNull($disabled->managerOf('thilanh@tricab.com'));
        self::assertSame([], (new FakeJsonHttpClient())->calls);
    }

    public function test_html_escapes_report_text_and_graph_mail_retries_without_sending_when_disabled(): void
    {
        $notice = new CustomerContactReportNotice(
            event: 'submitted_receipt',
            publicId: '11111111-1111-4111-8111-111111111111',
            recipients: ['raiser@tricab.com'],
            subject: 'Customer Contact ref',
            heading: 'ref',
            intro: 'Raised by Ada',
            fields: ['Problem' => '<script>alert(1)</script>'],
            linkUrl: 'https://app.tricab.test/customer-contacts/11111111-1111-4111-8111-111111111111',
            linkLabel: 'Open',
        );
        $html = (new CustomerContactReportEmailTemplate())->render($notice)['html'];
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);

        $http = new FakeJsonHttpClient();
        $http->queue = [
            $this->json(200, ['access_token' => 'token', 'expires_in' => 3600]),
            $this->json(429, []),
            $this->json(202, []),
        ];
        $sender = new GraphCustomerContactReportNotifier($http, new CustomerContactReportEmailTemplate(), 'tenant', 'client', 'secret', 'cc@tricab.com', true);
        $sender->send($notice);
        self::assertCount(2, array_filter(
            $http->calls,
            static fn (array $call): bool => $call['kind'] === 'json',
        ));

        $denied = new FakeJsonHttpClient();
        $denied->queue = [
            $this->json(200, ['access_token' => 'token', 'expires_in' => 3600]),
            $this->json(403, ['error' => 'denied']),
        ];
        $blocked = new GraphCustomerContactReportNotifier($denied, new CustomerContactReportEmailTemplate(), 'tenant', 'client', 'secret', 'cc@tricab.com', true);
        try {
            $blocked->send($notice);
            self::fail('A Graph refusal was treated as delivered.');
        } catch (RuntimeException) {
            self::assertCount(1, array_filter(
                $denied->calls,
                static fn (array $call): bool => $call['kind'] === 'json',
            ));
        }

        $quiet = new FakeJsonHttpClient();
        (new GraphCustomerContactReportNotifier($quiet, new CustomerContactReportEmailTemplate(), 'tenant', 'client', 'secret', 'cc@tricab.com', false))->send($notice);
        self::assertSame([], $quiet->calls);
    }

    public function test_customer_contact_mail_and_the_module_stay_off_by_default(): void
    {
        $example = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
        self::assertIsString($example);
        self::assertStringContainsString("CUSTOMER_CONTACT_MODULE_ENABLED=false", $example);
        self::assertStringContainsString("CUSTOMER_CONTACT_EMAIL_ENABLED=false", $example);
        self::assertStringContainsString("CUSTOMER_CONTACT_COORDINATOR_LOGIN=\n", $example);
        self::assertStringContainsString("CUSTOMER_CONTACT_ACCOUNTS_LOGIN=\n", $example);
    }

    /**
     * @return array{0: InMemoryCustomerContactReportRepository, 1: CustomerContactReportNotifications, 2: IdentityCustomerContactReportAssigneeDirectory}
     */
    private function notifications(InMemoryCustomerContactReportNotifier $mail): array
    {
        $users = new InMemoryApiUserRepository();
        foreach ([
            'alex@tricab.com' => 'Alex Assignee',
            'sam@tricab.com' => 'Sam Reviewer',
            'pat@tricab.com' => 'Pat Picked',
            'accounts@tricab.com' => 'Accounts',
            'quality@tricab.com' => 'Quality Coordinator',
        ] as $email => $name) {
            $users->register(
                EmailAddress::fromString($email),
                $name,
                UserStatus::active(),
                [RoleCode::fromString('EMPLOYEE')],
                [PermissionCode::fromString('ACCOUNT.LOGIN')],
                EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-' . $email)),
            );
        }
        $reports = new InMemoryCustomerContactReportRepository();
        $staff = new IdentityCustomerContactReportAssigneeDirectory($users);
        $notifications = new CustomerContactReportNotifications(
            $mail,
            $reports,
            $staff,
            new CustomerContactReportNotificationSettings(
                webBaseUrl: 'https://app.tricab.test',
                coordinatorLogin: 'quality@tricab.com',
                accountsMailbox: 'accounts-copy@tricab.com',
            ),
        );

        return [$reports, $notifications, $staff];
    }

    private function insert(InMemoryCustomerContactReportRepository $reports): \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails
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
            tricabRefTrace: 'INV-1',
            traceNum: null,
            conNoteNo: null,
            tricabContact: 'sam@tricab.com',
            productCode: 'CAB-1',
            description: 'Cable failed',
        ));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: string}
     */
    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ];
    }

    private function latestNotice(InMemoryCustomerContactReportNotifier $mail): CustomerContactReportNotice
    {
        $index = array_key_last($mail->sent);
        self::assertNotNull($index);

        return $mail->sent[$index];
    }

    /**
     * @return list<string>
     */
    private function events(InMemoryCustomerContactReportNotifier $mail): array
    {
        return array_map(static fn (CustomerContactReportNotice $notice): string => $notice->event, $mail->sent);
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
            ['ACCOUNT.LOGIN', 'CUSTOMER_CONTACT.APPROVE'],
        );
    }
}
