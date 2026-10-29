<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\CustomerContactReport\Storage\InMemoryCustomerContactReportFileStore;
use TriCab\Api\Http\Action\CustomerContactReport\CustomerContactReportDtoSerializer;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Application\Service\AttachCustomerContactReportEvidenceService;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\CompleteCustomerContactReportSettlementService;
use TriCab\Core\CustomerContactReport\Application\Service\CustomerContactReportBlobWrite;
use TriCab\Core\CustomerContactReport\Application\Service\DecideCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\ListCustomerContactReportAttachmentsService;
use TriCab\Core\CustomerContactReport\Application\Service\ReopenCustomerContactReportService;
use TriCab\Core\CustomerContactReport\Application\Service\RequestCustomerContactReportApprovalService;
use TriCab\Core\CustomerContactReport\Application\Service\SaveCustomerContactReportSignatureService;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportFilePolicy;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CustomerContactReportFileTest extends TestCase
{
    use AssignsCustomerContactReports;

    public function test_evidence_accepts_the_legacy_types_and_rejects_the_rest(): void
    {
        [$service, $reports, $files] = $this->evidence();
        $report = $this->insert($reports);
        $visibility = new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com');

        foreach ([
            'photo.jpg' => self::jpeg(),
            'photo.jpeg' => self::jpeg(),
            'photo.png' => "\x89PNG\r\n\x1a\nrest",
            'photo.gif' => 'GIF89a',
            'photo.heic' => "\x00\x00\x00\x18ftypheic",
            'photo.heif' => "\x00\x00\x00\x18ftypheif",
            'note.pdf' => '%PDF-1.4',
        ] as $name => $bytes) {
            $stored = $service->execute($this->caller(), $report->publicId, $name, $bytes, null, [], $visibility);
            self::assertSame('evidence', $stored->kind);
            self::assertSame($name, $stored->fileName);
            self::assertSame(strlen($bytes), $stored->fileSize);
            self::assertSame(1, $stored->createdByUserId);
            self::assertSame('thilanh@tricab.com', $stored->createdByEmail);
        }
        self::assertCount(7, $files->objects);
        self::assertSame('submitted', $this->reload($reports, $report->publicId)->status);
        self::assertSame(0, $reports->numberSequenceRows());

        $this->assertFileCode(CustomerContactReportFileFailure::INVALID, fn () => $service->execute(
            $this->caller(), $report->publicId, 'photo.txt', 'hello', null, [], $visibility,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::INVALID, fn () => $service->execute(
            $this->caller(), $report->publicId, 'photo.png', self::jpeg(), 'image/png', [], $visibility,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::INVALID, fn () => $service->execute(
            $this->caller(), $report->publicId, 'photo.jpg', self::jpeg(), 'application/pdf', [], $visibility,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::INVALID, fn () => $service->execute(
            $this->caller(), $report->publicId, 'empty.jpg', '', null, [], $visibility,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::TOO_LARGE, fn () => $service->execute(
            $this->caller(), $report->publicId, 'big.jpg', self::jpeg(CustomerContactReportFilePolicy::MAX_BYTES - 2), null, [], $visibility,
        ));
        $exact = $service->execute(
            $this->caller(),
            $report->publicId,
            'edge.jpg',
            self::jpeg(CustomerContactReportFilePolicy::MAX_BYTES - 3),
            null,
            [],
            $visibility,
        );
        self::assertSame(CustomerContactReportFilePolicy::MAX_BYTES, $exact->fileSize);
        self::assertCount(8, $reports->attachmentsFor($report->publicId));
    }

    public function test_only_the_creator_can_attach_evidence_while_the_report_is_submitted(): void
    {
        [$service, $reports, $files] = $this->evidence();
        $report = $this->insert($reports);
        $owner = new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com');
        $reader = new CustomerContactReportVisibility(true, 9, 'reader@tricab.com');

        $this->assertFileCode(CustomerContactReportFileFailure::NOT_ALLOWED, fn () => $service->execute(
            $this->person(9, 'reader@tricab.com', 'Reader'),
            $report->publicId,
            'photo.jpg',
            self::jpeg(),
            null,
            [],
            $reader,
        ));
        try {
            $service->execute($this->caller(), $report->publicId, 'photo.jpg', self::jpeg(), null, ['kind' => 'credit_note'], $owner);
            self::fail('A client-chosen kind was accepted.');
        } catch (CustomerContactReportClientFieldRejected) {
            self::assertSame([], $files->objects);
        }
    }

    public function test_a_blob_failure_writes_nothing_and_a_metadata_failure_deletes_the_blob(): void
    {
        [$service, $reports, $files] = $this->evidence();
        $report = $this->insert($reports);
        $visibility = new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com');
        $files->failNextPut = true;
        $this->assertFileCode(CustomerContactReportFileFailure::UPLOAD_FAILED, fn () => $service->execute(
            $this->caller(), $report->publicId, 'photo.jpg', self::jpeg(), null, [], $visibility,
        ));
        self::assertSame([], $reports->attachmentsFor($report->publicId));
        self::assertSame([], $files->objects);

        $reports->interruptNextFileMetadata();
        try {
            $service->execute($this->caller(), $report->publicId, 'photo.jpg', self::jpeg(), null, [], $visibility);
            self::fail('The metadata write was supposed to fail.');
        } catch (RuntimeException $failure) {
            self::assertSame('metadata write failed', $failure->getMessage());
        }
        self::assertSame([], $reports->attachmentsFor($report->publicId));
        self::assertCount(1, $files->deleted);
        self::assertSame([], $files->objects);

        $reports->interruptNextFileMetadata();
        $files->failNextDelete = true;
        try {
            $service->execute($this->caller(), $report->publicId, 'photo.jpg', self::jpeg(), null, [], $visibility);
            self::fail('The metadata write was supposed to fail.');
        } catch (RuntimeException) {
            self::assertNotSame([], $files->deleteAttempts);
            self::assertNotSame([], $files->objects);
        }
    }

    public function test_the_accounts_assignee_completes_settlement_by_attaching_the_credit_note(): void
    {
        [$request, $decide, $reports, $files, $settlement] = $this->settlement();
        $report = $this->assignTo($reports, $this->insert($reports));
        $pending = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '80.00',
            'rowVersion' => $report->rowVersion,
        ]);
        $approved = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        self::assertNotNull($approved->settlement);
        $owner = new CustomerContactReportVisibility(false, 3, 'accounts@tricab.com');
        $stored = $settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $report->publicId,
            'note.pdf',
            '%PDF-1.4',
            null,
            ['rowVersion' => $approved->settlement->rowVersion, 'note' => 'CN-100'],
            $owner,
        );

        self::assertSame('credit_note', $stored->kind);
        $closed = $this->reload($reports, $report->publicId);
        self::assertSame('closed', $closed->status);
        self::assertSame('Accounts', $closed->closedBy);
        self::assertSame(1, $reports->numberSequenceRows());
        $rows = $reports->approvalsFor($report->publicId);
        $done = null;
        foreach ($rows as $row) {
            if ($row->kind === 'settlement') {
                $done = $row;
            }
        }
        self::assertNotNull($done);
        self::assertSame('approved', $done->status);
        self::assertSame('accounts@tricab.com', $done->approver);
        self::assertSame('CN-100', $done->note);
        self::assertNotNull($done->decidedAtUtc);

        $listed = new ListCustomerContactReportAttachmentsService($reports);
        $creator = $listed->execute($report->publicId, new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com'));
        self::assertSame([], $creator);
        $accounts = $listed->execute($report->publicId, $owner);
        self::assertSame('credit_note', $accounts[0]->kind);
        $register = $listed->execute($report->publicId, new CustomerContactReportVisibility(true, 9, 'reader@tricab.com'));
        self::assertSame('credit_note', $register[0]->kind);

        $this->assertFileCode(CustomerContactReportFileFailure::SETTLEMENT_NOT_PENDING, fn () => $settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $report->publicId,
            'again.pdf',
            '%PDF-1.4',
            null,
            ['rowVersion' => $done->rowVersion],
            $owner,
        ));
        self::assertCount(1, array_filter(
            $reports->attachmentsFor($report->publicId),
            static fn ($attachment): bool => $attachment->kind === 'credit_note',
        ));
    }

    public function test_credit_note_is_refused_unless_the_settlement_step_is_pending_for_that_person(): void
    {
        [$request, $decide, $reports, $files, $settlement] = $this->settlement();
        $report = $this->assignTo($reports, $this->insert($reports));
        $owner = new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com');
        $this->assertFileCode(CustomerContactReportFileFailure::CREDIT_NOTE_NOT_ALLOWED, fn () => $settlement->execute(
            $this->caller(), $report->publicId, 'note.pdf', '%PDF-1.4', null, ['rowVersion' => $report->rowVersion], $owner,
        ));

        $pending = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '10.00',
            'rowVersion' => $report->rowVersion,
        ]);
        $this->assertFileCode(CustomerContactReportFileFailure::CREDIT_NOTE_NOT_ALLOWED, fn () => $settlement->execute(
            $this->caller(), $report->publicId, 'note.pdf', '%PDF-1.4', null, ['rowVersion' => $pending->approval->rowVersion], $owner,
        ));
        $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'declined',
            'note' => 'No.',
            'rowVersion' => $pending->approval->rowVersion,
        ]);
        $this->assertFileCode(CustomerContactReportFileFailure::CREDIT_NOTE_NOT_ALLOWED, fn () => $settlement->execute(
            $this->caller(), $report->publicId, 'note.gif', 'GIF89a', null, ['rowVersion' => $pending->approval->rowVersion], $owner,
        ));

        $second = $this->assignTo($reports, $this->insert($reports));
        $raised = $request->execute($this->caller(), $second->publicId, 'credit', [
            'creditTotal' => '10.00',
            'rowVersion' => $second->rowVersion,
        ]);
        $approved = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $second->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $raised->approval->rowVersion,
        ]);
        self::assertNotNull($approved->settlement);
        $accounts = new CustomerContactReportVisibility(false, 3, 'accounts@tricab.com');
        $this->assertFileCode(CustomerContactReportFileFailure::SETTLEMENT_NOT_ASSIGNEE, fn () => $settlement->execute(
            $this->caller(), $second->publicId, 'note.pdf', '%PDF-1.4', null, ['rowVersion' => $approved->settlement->rowVersion], $owner,
        ));
        $this->assertCode(CustomerContactReportApprovalFailure::CONFLICT, fn () => $settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $second->publicId,
            'note.pdf',
            '%PDF-1.4',
            null,
            ['rowVersion' => $raised->approval->rowVersion],
            $accounts,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::INVALID, fn () => $settlement->execute(
            $this->person(3, 'accounts@tricab.com', 'Accounts'),
            $second->publicId,
            'note.gif',
            'GIF89a',
            null,
            ['rowVersion' => $approved->settlement->rowVersion],
            $accounts,
        ));
        self::assertSame([], $files->objects);
        self::assertSame('pending', $reports->approvalsFor($second->publicId)[1]->status);
    }

    public function test_credit_note_blob_and_metadata_failures_leave_settlement_pending(): void
    {
        [$request, $decide, $reports, $files, $settlement] = $this->settlement();
        $report = $this->assignTo($reports, $this->insert($reports));
        $raised = $request->execute($this->caller(), $report->publicId, 'credit', [
            'creditTotal' => '10.00',
            'rowVersion' => $report->rowVersion,
        ]);
        $approved = $decide->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, 'credit', [
            'decision' => 'approved',
            'rowVersion' => $raised->approval->rowVersion,
        ]);
        self::assertNotNull($approved->settlement);
        $accounts = new CustomerContactReportVisibility(false, 3, 'accounts@tricab.com');
        $body = ['rowVersion' => $approved->settlement->rowVersion];
        $actor = $this->person(3, 'accounts@tricab.com', 'Accounts');

        $files->failNextPut = true;
        $this->assertFileCode(CustomerContactReportFileFailure::UPLOAD_FAILED, fn () => $settlement->execute(
            $actor, $report->publicId, 'note.pdf', '%PDF-1.4', null, $body, $accounts,
        ));
        self::assertSame('pending', $reports->approvalsFor($report->publicId)[1]->status);

        $reports->interruptNextFileMetadata();
        try {
            $settlement->execute($actor, $report->publicId, 'note.pdf', '%PDF-1.4', null, $body, $accounts);
            self::fail('The metadata write was supposed to fail.');
        } catch (RuntimeException) {
            self::assertSame('pending', $reports->approvalsFor($report->publicId)[1]->status);
            self::assertSame([], array_filter(
                $reports->attachmentsFor($report->publicId),
                static fn ($attachment): bool => $attachment->kind === 'credit_note',
            ));
            self::assertSame([], $files->objects);
        }
    }

    public function test_only_the_icar_assignee_can_record_a_signature_and_reopen_allows_another(): void
    {
        [$sign, $reports, $files] = $this->signatures();
        $report = $reports->seedCopy(
            $this->insert($reports),
            1,
            'thilanh@tricab.com',
            'assigned',
            '2609-001M-SLS',
            'icar',
            'SLS',
            'alex@tricab.com',
        );
        $assignee = new CustomerContactReportVisibility(false, 2, 'alex@tricab.com');
        $png = $this->pngDataUrl();
        $signed = $sign->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, [
            'signature' => $png,
            'icarOperatorName' => 'Alex Assignee',
            'rowVersion' => $report->rowVersion,
        ], $assignee);

        self::assertSame('assigned', $signed->status);
        self::assertSame('Alex Assignee', $signed->icarOperatorName);
        self::assertSame('2026-09-30', $signed->icarSignedAt);
        self::assertNotNull($signed->icarSignatureUrl);
        self::assertStringStartsWith('memory://cc/', $signed->icarSignatureUrl);
        $firstUrl = $signed->icarSignatureUrl;

        $hidden = CustomerContactReportDtoSerializer::detail($signed, false, [], 1, 'thilanh@tricab.com');
        self::assertArrayNotHasKey('icarSignatureUrl', $hidden);
        $shown = CustomerContactReportDtoSerializer::detail($signed, false, [], 2, 'alex@tricab.com', [], true);
        self::assertSame($firstUrl, $shown['icarSignatureUrl'] ?? null);

        $this->assertFileCode(CustomerContactReportFileFailure::SIGNATURE_EXISTS, fn () => $sign->execute(
            $this->person(2, 'alex@tricab.com', 'Alex Assignee'),
            $report->publicId,
            ['signature' => $png, 'icarOperatorName' => 'Someone else', 'rowVersion' => $signed->rowVersion],
            $assignee,
        ));
        $branch = $reports->seedCopy($report, 1, 'thilanh@tricab.com', 'assigned', '2609-002M-SLS', 'branch', 'SLS', 'alex@tricab.com');
        $this->assertFileCode(CustomerContactReportFileFailure::SIGNATURE_NOT_ALLOWED, fn () => $sign->execute(
            $this->person(2, 'alex@tricab.com', 'Alex Assignee'),
            $branch->publicId,
            ['signature' => $png, 'icarOperatorName' => 'Alex Assignee', 'rowVersion' => $branch->rowVersion],
            $assignee,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::SIGNATURE_NOT_ALLOWED, fn () => $sign->execute(
            $this->caller(),
            $report->publicId,
            ['signature' => $png, 'icarOperatorName' => 'Thilan', 'rowVersion' => $signed->rowVersion],
            new CustomerContactReportVisibility(false, 1, 'thilanh@tricab.com'),
        ));
        $hidden = null;
        try {
            $sign->execute(
                $this->person(9, 'reader@tricab.com', 'Reader'),
                $report->publicId,
                ['signature' => $png, 'icarOperatorName' => 'Reader', 'rowVersion' => $signed->rowVersion],
                new CustomerContactReportVisibility(false, 9, 'reader@tricab.com'),
            );
        } catch (CustomerContactReportNotFound $exception) {
            $hidden = $exception;
        }
        self::assertInstanceOf(CustomerContactReportNotFound::class, $hidden, 'An unrelated employee opened the signature.');
        $this->assertFileCode(CustomerContactReportFileFailure::SIGNATURE_NOT_ALLOWED, fn () => $sign->execute(
            $this->person(9, 'reader@tricab.com', 'Reader'),
            $report->publicId,
            ['signature' => $png, 'icarOperatorName' => 'Reader', 'rowVersion' => $signed->rowVersion],
            new CustomerContactReportVisibility(true, 9, 'reader@tricab.com'),
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::SIGNATURE_INVALID, fn () => $sign->execute(
            $this->person(2, 'alex@tricab.com', 'Alex Assignee'),
            $branch->publicId,
            ['signature' => 'data:image/png;base64,' . str_repeat('A', 1400001), 'icarOperatorName' => 'Alex', 'rowVersion' => $branch->rowVersion],
            $assignee,
        ));
        $this->assertFileCode(CustomerContactReportFileFailure::SIGNATURE_INVALID, fn () => $sign->execute(
            $this->person(2, 'alex@tricab.com', 'Alex Assignee'),
            $branch->publicId,
            ['signature' => 'data:image/svg+xml;base64,PHN2Zy8+', 'icarOperatorName' => 'Alex', 'rowVersion' => $branch->rowVersion],
            $assignee,
        ));

        $complete = new CompleteCustomerContactReportService($reports, FixedClock::utc('2026-09-30 02:00:00'));
        $completed = $complete->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, [
            'rowVersion' => $signed->rowVersion,
        ]);
        $reopen = new ReopenCustomerContactReportService($reports, FixedClock::utc('2026-09-30 03:00:00'));
        $reopened = $reopen->execute($report->publicId, ['rowVersion' => $completed->rowVersion]);
        self::assertNull($reopened->icarSignatureUrl);
        self::assertSame($firstUrl, $reports->signatureSupersessions($report->publicId)[0]->fileUrl);
        self::assertArrayHasKey(substr($firstUrl, strlen('memory://')), $files->objects);
        $listed = (new ListCustomerContactReportAttachmentsService($reports))->execute(
            $report->publicId,
            new CustomerContactReportVisibility(true, 9, 'reader@tricab.com'),
        );
        self::assertSame([], array_filter(
            $listed,
            static fn ($attachment): bool => $attachment->kind === 'signature_superseded',
        ));

        $again = $sign->execute($this->person(2, 'alex@tricab.com', 'Alex Assignee'), $report->publicId, [
            'signature' => $png,
            'icarOperatorName' => 'Alex Assignee',
            'rowVersion' => $reopened->rowVersion,
        ], $assignee);
        self::assertNotSame($firstUrl, $again->icarSignatureUrl);
        self::assertCount(2, $files->objects);
    }

    /**
     * @return array{0: AttachCustomerContactReportEvidenceService, 1: InMemoryCustomerContactReportRepository, 2: InMemoryCustomerContactReportFileStore}
     */
    private function evidence(): array
    {
        $reports = new InMemoryCustomerContactReportRepository();
        $files = new InMemoryCustomerContactReportFileStore();

        return [
            new AttachCustomerContactReportEvidenceService($reports, new CustomerContactReportBlobWrite($files), FixedClock::utc('2026-09-30 01:00:00')),
            $reports,
            $files,
        ];
    }

    /**
     * @return array{0: RequestCustomerContactReportApprovalService, 1: DecideCustomerContactReportApprovalService, 2: InMemoryCustomerContactReportRepository, 3: InMemoryCustomerContactReportFileStore, 4: CompleteCustomerContactReportSettlementService}
     */
    private function settlement(): array
    {
        $users = new InMemoryApiUserRepository();
        $this->register($users, 'alex@tricab.com', 'Alex Assignee');
        $this->register($users, 'accounts@tricab.com', 'Accounts');
        $reports = new InMemoryCustomerContactReportRepository();
        $files = new InMemoryCustomerContactReportFileStore();
        $staff = new IdentityCustomerContactReportAssigneeDirectory($users);
        $clock = FixedClock::utc('2026-09-30 01:00:00');

        return [
            new RequestCustomerContactReportApprovalService($reports, $clock),
            new DecideCustomerContactReportApprovalService($reports, $staff, $clock, new CustomerContactReportAccountsLogin('accounts@tricab.com')),
            $reports,
            $files,
            new CompleteCustomerContactReportSettlementService($reports, new CustomerContactReportBlobWrite($files), $clock),
        ];
    }

    /**
     * @return array{0: SaveCustomerContactReportSignatureService, 1: InMemoryCustomerContactReportRepository, 2: InMemoryCustomerContactReportFileStore}
     */
    private function signatures(): array
    {
        $reports = new InMemoryCustomerContactReportRepository();
        $files = new InMemoryCustomerContactReportFileStore();

        return [
            new SaveCustomerContactReportSignatureService($reports, new CustomerContactReportBlobWrite($files), FixedClock::utc('2026-09-30 01:00:00')),
            $reports,
            $files,
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

    private function reload(InMemoryCustomerContactReportRepository $reports, string $publicId): CustomerContactReportDetails
    {
        $found = $reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        );
        self::assertNotNull($found);

        return $found;
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

    private static function jpeg(int $pad = 0): string
    {
        return "\xFF\xD8\xFF" . str_repeat('A', $pad);
    }

    private function pngDataUrl(): string
    {
        return 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nrest");
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertFileCode(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Expected ' . $code . '.');
        } catch (CustomerContactReportFileFailure $failure) {
            self::assertSame($code, $failure->errorCode);
        }
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertCode(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Expected ' . $code . '.');
        } catch (CustomerContactReportApprovalFailure $failure) {
            self::assertSame($code, $failure->errorCode);
        }
    }
}
