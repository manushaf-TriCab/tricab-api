<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use Throwable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCreditNote;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportBlobPath;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportFilePolicy;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class CompleteCustomerContactReportSettlementService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'kind',
        'status',
        'fileUrl',
        'fileName',
        'fileSize',
        'contentType',
        'approver',
        'approverName',
        'approverEmail',
        'approverUserId',
        'decidedAt',
        'decidedAtUtc',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportBlobWrite $blobs,
        private readonly Clock $clock,
        private readonly ?CustomerContactReportNotifications $notifications = null,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function execute(
        AuthenticatedUser $actor,
        string $publicId,
        string $originalName,
        string $bytes,
        ?string $clientMediaType,
        array $fields,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportStoredAttachment {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        $rowVersion = $fields['rowVersion'] ?? null;
        if (!is_string($rowVersion) || trim($rowVersion) === '') {
            throw InvalidCustomerContactReportValue::for('rowVersion', 'is required.');
        }
        $rowVersion = trim($rowVersion);
        CustomerContactReportRowVersion::fromBase64($rowVersion);
        $note = self::note($fields);

        $id = CustomerContactReportPublicId::fromString($publicId);
        $report = $this->reports->findVisible($id, $visibility);
        if ($report === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }
        $this->assertReady($report->publicId, $actor, $rowVersion);

        $inspected = CustomerContactReportFilePolicy::creditNote()->inspect($originalName, $bytes, $clientMediaType);
        $extension = strtolower(pathinfo($inspected['fileName'], PATHINFO_EXTENSION));
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $stored = $this->blobs->put(
            CustomerContactReportBlobPath::creditNote($now, $extension),
            $inspected['contentType'],
            $bytes,
        );

        try {
            $attachment = $this->reports->completeSettlement(new CustomerContactReportCreditNote(
                publicId: $report->publicId,
                expectedSettlementRowVersion: $rowVersion,
                fileUrl: $stored->url,
                fileName: $inspected['fileName'],
                fileSize: $inspected['size'],
                contentType: $inspected['contentType'],
                note: $note,
                actorUserId: $actor->userId,
                actorEmail: strtolower(trim($actor->emailAddress)),
                decidedAtUtc: $now,
            ));
        } catch (Throwable $failure) {
            $this->blobs->rollback($stored);
            throw $failure;
        }
        $this->notifications?->settlementCompleted($report->publicId);

        return $attachment;
    }

    private function assertReady(string $publicId, AuthenticatedUser $actor, string $rowVersion): void
    {
        $credit = null;
        $settlement = null;
        foreach ($this->reports->approvalsFor($publicId) as $approval) {
            if ($approval->kind === 'credit') {
                $credit = $approval;
            }
            if ($approval->kind === 'settlement') {
                $settlement = $approval;
            }
        }
        if ($credit === null || $credit->status !== 'approved' || $settlement === null) {
            throw CustomerContactReportFileFailure::creditNoteNotAllowed(
                'A credit note can be attached only after the credit is approved and Accounts has the step.',
            );
        }
        if ($settlement->status !== 'pending') {
            throw CustomerContactReportFileFailure::settlementNotPending();
        }
        if (!CustomerContactReportAssigneeMatch::isCurrent(
            $actor->userId,
            $actor->emailAddress,
            $settlement->approverUserId,
            $settlement->approver,
            $settlement->approverEmail,
        )) {
            throw CustomerContactReportFileFailure::settlementNotAssignee();
        }
        $expected = CustomerContactReportRowVersion::fromBase64($rowVersion);
        $actual = CustomerContactReportRowVersion::fromBase64($settlement->rowVersion);
        if (!$expected->equals($actual)) {
            throw CustomerContactReportApprovalFailure::conflict();
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function note(array $fields): ?string
    {
        if (!array_key_exists('note', $fields)) {
            return null;
        }
        $note = $fields['note'];
        if (!is_string($note)) {
            throw InvalidCustomerContactReportValue::for('note', 'must be text.');
        }
        $trimmed = trim($note);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > 4000) {
            throw InvalidCustomerContactReportValue::for('note', 'is too long.');
        }

        return $trimmed;
    }
}
