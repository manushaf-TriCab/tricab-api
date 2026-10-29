<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Persistence;

use RuntimeException;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalDecision;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAssignment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportClosure;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCompletion;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCounts;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportReopen;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListResult;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use DateTimeImmutable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCreditNote;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportEvidence;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSignature;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdate;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdateOutcome;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportApprovalSeparation;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportNumber;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSearch;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSequenceBook;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportStatus;

final class InMemoryCustomerContactReportRepository implements CustomerContactReportRepository
{
    /** @var list<CustomerContactReportDetails> */
    private array $reports = [];

    /** @var list<CustomerContactReportStoredAttachment> */
    private array $attachments = [];

    /** @var list<CustomerContactReportApproval> */
    private array $approvals = [];

    private int $nextId = 1;

    private int $nextApprovalId = 1;

    private int $nextVersion = 1;

    private readonly CustomerContactReportSequenceBook $sequences;

    private bool $interruptAfterSequence = false;

    private bool $interruptAfterSignature = false;

    private bool $interruptFileMetadata = false;

    public function __construct()
    {
        $this->sequences = new CustomerContactReportSequenceBook();
    }

    public function insert(NewCustomerContactReport $report): CustomerContactReportDetails
    {
        $details = new CustomerContactReportDetails(
            reportId: $this->nextId++,
            publicId: $report->publicId,
            rowVersion: $this->nextRowVersion(),
            reportDate: $report->reportDate,
            location: $report->location,
            customerCode: $report->customerCode,
            customerContact: $report->customerContact,
            tricabRefTrace: $report->tricabRefTrace,
            traceNum: $report->traceNum,
            conNoteNo: $report->conNoteNo,
            tricabContact: $report->tricabContact,
            productCode: $report->productCode,
            description: $report->description,
            status: CustomerContactReportStatus::SUBMITTED,
            route: null,
            ccNumber: null,
            errorCode: null,
            assignedTo: null,
            assignedToUserId: null,
            assignedToName: null,
            assignedToEmail: null,
            assignedBy: null,
            assignedAtUtc: null,
            assigneeActionTaken: null,
            assigneeFindings: null,
            assigneeCompletedAtUtc: null,
            closedBy: null,
            closedByUserId: null,
            closedDate: null,
            closedAtUtc: null,
            createdByUserId: $report->createdByUserId,
            createdByEmail: $report->createdByEmail,
            createdByName: $report->createdByName,
            createdAtUtc: $report->createdAtUtc,
            updatedAtUtc: $report->createdAtUtc,
            investigationDueDate: null,
            ccAssessedBy: null,
            creditRequired: $report->creditRequired,
            restockingFeeApplies: $report->restockingFeeApplies,
            actionsTaken: null,
            schedulingInformed: null,
            returnArrangedBy: null,
            expectedReturnDate: null,
            goodsReturned: null,
            goodsReturnedDate: null,
            carrierBooked: null,
            carrierBookedDate: null,
            creditRaised: null,
            creditRaisedDate: null,
            goodsReplaced: null,
            goodsReplacedDate: null,
            carRaised: null,
            carRaisedDate: null,
            valueExGst: $report->figures?->valueExGst,
            restockingAmount: $report->figures?->restockingAmount,
            subTotal: $report->figures?->subTotal,
            gst: $report->figures?->gst,
            creditTotal: $report->figures?->creditTotal,
            rootCause: null,
            correctiveAction: null,
            notes: null,
            creditReviewedBy: null,
            creditReviewedDate: null,
            icarOperatorName: null,
            icarSignedAt: null,
            icarSignatureUrl: null,
            orderNo: $report->orderNo,
            invoiceNo: $report->invoiceNo,
            creditType: $report->creditType,
            quotedAmount: $report->quotedAmount,
            invoicedAmount: $report->invoicedAmount,
            addGst: $report->addGst,
            requestedActions: $report->requestedActions,
            lines: $report->lines,
        );
        $this->reports[] = $details;

        return $details;
    }

    public function findVisible(
        CustomerContactReportPublicId $publicId,
        CustomerContactReportVisibility $visibility,
    ): ?CustomerContactReportDetails {
        foreach ($this->reports as $report) {
            if ($report->publicId !== $publicId->toString()) {
                continue;
            }
            if (!$this->canSee($report, $visibility)) {
                return null;
            }

            return $report;
        }

        return null;
    }

    public function list(
        CustomerContactReportListCriteria $criteria,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportListResult {
        $visible = array_values(array_filter(
            $this->reports,
            fn (CustomerContactReportDetails $report): bool => $this->canSee($report, $visibility),
        ));
        $counted = array_values(array_filter(
            $visible,
            fn (CustomerContactReportDetails $report): bool => $this->matches($report, $criteria->withoutStatus()),
        ));
        $filtered = array_values(array_filter(
            $counted,
            fn (CustomerContactReportDetails $report): bool => $criteria->status === null || $report->status === $criteria->status,
        ));
        usort($filtered, $this->compare(...));
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $page = array_slice($filtered, $offset, $criteria->pageSize);

        return new CustomerContactReportListResult(
            items: $page,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, count($filtered)),
            counts: $this->counts($counted),
            approvalSummaries: $this->approvalSummaries(array_map(
                static fn (CustomerContactReportDetails $report): string => $report->publicId,
                $page,
            )),
        );
    }

    public function assign(CustomerContactReportAssignment $assignment): CustomerContactReportDetails
    {
        $index = $this->indexOf($assignment->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($assignment->publicId);
        }

        $current = $this->reports[$index];
        $expected = CustomerContactReportRowVersion::fromBase64($assignment->expectedRowVersion);
        $actual = CustomerContactReportRowVersion::fromBase64($current->rowVersion);
        if (!$expected->equals($actual)) {
            throw CustomerContactReportConflict::stale();
        }

        if ($current->status !== CustomerContactReportStatus::SUBMITTED && $current->status !== CustomerContactReportStatus::ASSIGNED) {
            throw CustomerContactReportConflict::notAssignable($current->status);
        }

        $transactionId = 1;
        try {
            $ccNumber = $current->ccNumber;
            if ($current->status === CustomerContactReportStatus::SUBMITTED) {
                $sequence = $this->sequences->allocate($transactionId, (int) $current->reportDate->format('Y'));
                if ($this->interruptAfterSequence) {
                    $this->interruptAfterSequence = false;
                    throw new RuntimeException('assignment interrupted after sequence allocation');
                }
                $ccNumber = CustomerContactReportNumber::format(
                    $current->reportDate,
                    $sequence,
                    $current->location,
                    $assignment->errorCode,
                );
            }

            $updated = $current->withAssignment(
                rowVersion: $this->nextRowVersion(),
                ccNumber: (string) $ccNumber,
                route: $assignment->route,
                errorCode: $assignment->errorCode,
                assignedTo: $assignment->assigneeLogin,
                assignedToUserId: $assignment->assigneeUserId,
                assignedToName: $assignment->assigneeName,
                assignedToEmail: $assignment->assigneeEmail,
                assignedBy: $assignment->assignedByEmail,
                assignedAtUtc: $assignment->assignedAtUtc,
                updatedAtUtc: $assignment->assignedAtUtc,
            );
            $this->replace($index, $updated);
            $this->sequences->commit($transactionId);
            // A pending credit follows the reassignment: the assignee owns it.
            $credit = $this->approvalFor($updated->reportId, 'credit');
            if ($credit !== null && $credit->status === 'pending') {
                $this->storeApproval(new CustomerContactReportApproval(
                    approvalId: $credit->approvalId,
                    reportId: $credit->reportId,
                    publicId: $credit->publicId,
                    rowVersion: $this->nextRowVersion(),
                    kind: 'credit',
                    status: 'pending',
                    approver: $assignment->assigneeLogin,
                    approverUserId: $assignment->assigneeUserId,
                    approverName: $assignment->assigneeName,
                    approverEmail: $assignment->assigneeEmail,
                    requestedBy: $credit->requestedBy,
                    requestedByUserId: $credit->requestedByUserId,
                    requestedAtUtc: $credit->requestedAtUtc,
                    note: $credit->note,
                    decidedAtUtc: null,
                ));
            }

            return $updated;
        } catch (\Throwable $exception) {
            $this->replace($index, $current);
            $this->sequences->rollBack($transactionId);
            throw $exception;
        }
    }

    public function complete(CustomerContactReportCompletion $completion): CustomerContactReportDetails
    {
        $index = $this->indexOf($completion->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($completion->publicId);
        }

        $current = $this->reports[$index];
        $this->assertRowVersion($current, $completion->expectedRowVersion);
        if ($current->status !== CustomerContactReportStatus::ASSIGNED) {
            throw CustomerContactReportConflict::notCompletable($current->status);
        }
        if (!CustomerContactReportAssigneeMatch::isCurrent(
            $completion->actorUserId,
            $completion->actorEmail,
            $current->assignedToUserId,
            $current->assignedTo,
            $current->assignedToEmail,
        )) {
            throw new CustomerContactReportNotAssignee();
        }

        $updated = $current->withCompletion(
            rowVersion: $this->nextRowVersion(),
            assigneeActionTaken: $completion->actionProvided ? $completion->assigneeActionTaken : $current->assigneeActionTaken,
            assigneeFindings: $completion->findingsProvided ? $completion->assigneeFindings : $current->assigneeFindings,
            assigneeCompletedAtUtc: $completion->completedAtUtc,
            updatedAtUtc: $completion->completedAtUtc,
        );
        $this->replace($index, $updated);

        return $updated;
    }

    public function close(CustomerContactReportClosure $closure): CustomerContactReportDetails
    {
        $index = $this->indexOf($closure->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($closure->publicId);
        }

        $current = $this->reports[$index];
        $this->assertRowVersion($current, $closure->expectedRowVersion);
        if ($current->status !== CustomerContactReportStatus::COMPLETED) {
            throw CustomerContactReportConflict::notClosable($current->status);
        }

        $updated = $current->withClosure(
            rowVersion: $this->nextRowVersion(),
            closedBy: $closure->closedBy,
            closedByUserId: $closure->closedByUserId,
            closedDate: $closure->closedDate,
            closedAtUtc: $closure->closedAtUtc,
            updatedAtUtc: $closure->closedAtUtc,
        );
        $this->replace($index, $updated);

        return $updated;
    }

    public function reopen(CustomerContactReportReopen $reopen): CustomerContactReportDetails
    {
        $index = $this->indexOf($reopen->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($reopen->publicId);
        }

        $current = $this->reports[$index];
        $previousAttachments = $this->attachments;
        try {
            $this->assertRowVersion($current, $reopen->expectedRowVersion);
            if ($current->status !== CustomerContactReportStatus::COMPLETED && $current->status !== CustomerContactReportStatus::CLOSED) {
                throw CustomerContactReportConflict::notReopenable($current->status);
            }
            if ($current->assignedTo === null || trim($current->assignedTo) === '') {
                throw CustomerContactReportConflict::notReopenable($current->status);
            }

            $signature = trim((string) $current->icarSignatureUrl);
            $clearSignature = $signature !== '';
            if ($clearSignature) {
                $signedAt = trim((string) $current->icarSignedAt);
                $this->attachments[] = new CustomerContactReportStoredAttachment(
                    reportId: $current->reportId,
                    publicId: $current->publicId,
                    fileUrl: (string) $current->icarSignatureUrl,
                    fileName: 'signature-superseded-' . ($signedAt !== '' ? $signedAt : $reopen->reopenedAtUtc->format('Y-m-d')) . '.png',
                    fileSize: 0,
                    contentType: 'image/png',
                    kind: 'signature_superseded',
                );
                if ($this->interruptAfterSignature) {
                    $this->interruptAfterSignature = false;
                    throw new RuntimeException('reopen interrupted after signature attachment');
                }
            }

            $updated = $current->withReopen(
                rowVersion: $this->nextRowVersion(),
                clearSignature: $clearSignature,
                updatedAtUtc: $reopen->reopenedAtUtc,
            );
            $this->replace($index, $updated);

            return $updated;
        } catch (\Throwable $exception) {
            $this->replace($index, $current);
            $this->attachments = $previousAttachments;
            throw $exception;
        }
    }

    /**
     * @return list<CustomerContactReportStoredAttachment>
     */
    public function signatureSupersessions(string $publicId): array
    {
        $matched = [];
        foreach ($this->attachments as $attachment) {
            if ($attachment->publicId === strtolower($publicId) && $attachment->kind === 'signature_superseded') {
                $matched[] = $attachment;
            }
        }

        return $matched;
    }

    public function interruptNextReopenAfterSignatureAttachment(): void
    {
        $this->interruptAfterSignature = true;
    }

    /**
     * Test fixture. Production signatures are not written by this phase.
     */
    public function seedActiveSignature(string $publicId, string $operatorName, string $signedAt, string $signatureUrl): CustomerContactReportDetails
    {
        $index = $this->indexOf($publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        $current = $this->reports[$index];
        $updated = new CustomerContactReportDetails(
            reportId: $current->reportId,
            publicId: $current->publicId,
            rowVersion: $this->nextRowVersion(),
            reportDate: $current->reportDate,
            location: $current->location,
            customerCode: $current->customerCode,
            customerContact: $current->customerContact,
            tricabRefTrace: $current->tricabRefTrace,
            traceNum: $current->traceNum,
            conNoteNo: $current->conNoteNo,
            tricabContact: $current->tricabContact,
            productCode: $current->productCode,
            description: $current->description,
            status: $current->status,
            route: $current->route,
            ccNumber: $current->ccNumber,
            errorCode: $current->errorCode,
            assignedTo: $current->assignedTo,
            assignedToUserId: $current->assignedToUserId,
            assignedToName: $current->assignedToName,
            assignedToEmail: $current->assignedToEmail,
            assignedBy: $current->assignedBy,
            assignedAtUtc: $current->assignedAtUtc,
            assigneeActionTaken: $current->assigneeActionTaken,
            assigneeFindings: $current->assigneeFindings,
            assigneeCompletedAtUtc: $current->assigneeCompletedAtUtc,
            closedBy: $current->closedBy,
            closedByUserId: $current->closedByUserId,
            closedDate: $current->closedDate,
            closedAtUtc: $current->closedAtUtc,
            createdByUserId: $current->createdByUserId,
            createdByEmail: $current->createdByEmail,
            createdByName: $current->createdByName,
            createdAtUtc: $current->createdAtUtc,
            updatedAtUtc: $current->updatedAtUtc,
            investigationDueDate: $current->investigationDueDate,
            ccAssessedBy: $current->ccAssessedBy,
            creditRequired: $current->creditRequired,
            restockingFeeApplies: $current->restockingFeeApplies,
            actionsTaken: $current->actionsTaken,
            schedulingInformed: $current->schedulingInformed,
            returnArrangedBy: $current->returnArrangedBy,
            expectedReturnDate: $current->expectedReturnDate,
            goodsReturned: $current->goodsReturned,
            goodsReturnedDate: $current->goodsReturnedDate,
            carrierBooked: $current->carrierBooked,
            carrierBookedDate: $current->carrierBookedDate,
            creditRaised: $current->creditRaised,
            creditRaisedDate: $current->creditRaisedDate,
            goodsReplaced: $current->goodsReplaced,
            goodsReplacedDate: $current->goodsReplacedDate,
            carRaised: $current->carRaised,
            carRaisedDate: $current->carRaisedDate,
            valueExGst: $current->valueExGst,
            restockingAmount: $current->restockingAmount,
            subTotal: $current->subTotal,
            gst: $current->gst,
            creditTotal: $current->creditTotal,
            rootCause: $current->rootCause,
            correctiveAction: $current->correctiveAction,
            notes: $current->notes,
            creditReviewedBy: $current->creditReviewedBy,
            creditReviewedDate: $current->creditReviewedDate,
            icarOperatorName: $operatorName,
            icarSignedAt: $signedAt,
            icarSignatureUrl: trim($signatureUrl) === '' ? null : $signatureUrl,
        );
        $this->replace($index, $updated);

        return $updated;
    }

    public function requestApproval(CustomerContactReportApprovalRequest $request): CustomerContactReportApprovalOutcome
    {
        $index = $this->indexOf($request->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($request->publicId);
        }
        $current = $this->reports[$index];
        $this->assertRowVersion($current, $request->expectedReportRowVersion);
        $existing = $this->approvalFor($current->reportId, $request->kind);
        if ($existing !== null && $existing->status !== 'pending') {
            throw CustomerContactReportApprovalFailure::notPending($existing->status);
        }

        $report = $current;
        if ($request->writeCreditFigures) {
            $report = $current->withCreditFigures(
                $this->nextRowVersion(),
                $request->requestedAtUtc,
                $request->valueExGst,
                $request->restockingAmount,
                $request->subTotal,
                $request->gst,
                $request->creditTotal,
            );
            $this->replace($index, $report);
        }

        $approval = new CustomerContactReportApproval(
            approvalId: $existing === null ? $this->nextApprovalId++ : $existing->approvalId,
            reportId: $report->reportId,
            publicId: $report->publicId,
            rowVersion: $this->nextRowVersion(),
            kind: $request->kind,
            status: 'pending',
            approver: $request->approverLogin,
            approverUserId: $request->approverUserId,
            approverName: $request->approverName,
            approverEmail: $request->approverEmail,
            requestedBy: $request->requestedBy,
            requestedByUserId: $request->requestedByUserId,
            requestedAtUtc: $request->requestedAtUtc,
            note: $existing?->note,
            decidedAtUtc: null,
        );
        $this->storeApproval($approval);

        return new CustomerContactReportApprovalOutcome($report, $approval, null);
    }

    public function decideApproval(CustomerContactReportApprovalDecision $decision): CustomerContactReportApprovalOutcome
    {
        $index = $this->indexOf($decision->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($decision->publicId);
        }
        $report = $this->reports[$index];
        $existing = $this->approvalFor($report->reportId, $decision->kind);
        if ($existing === null) {
            throw CustomerContactReportApprovalFailure::notFound($decision->kind);
        }
        $expected = CustomerContactReportRowVersion::fromBase64($decision->expectedRowVersion);
        $actual = CustomerContactReportRowVersion::fromBase64($existing->rowVersion);
        if (!$expected->equals($actual)) {
            throw CustomerContactReportApprovalFailure::conflict();
        }
        if (!CustomerContactReportAssigneeMatch::isCurrent(
            $decision->actorUserId,
            $decision->actorEmail,
            $existing->approverUserId,
            $existing->approver,
            $existing->approverEmail,
        )) {
            throw CustomerContactReportApprovalFailure::notApprover();
        }
        if (CustomerContactReportApprovalSeparation::samePerson(
            $decision->actorUserId,
            $decision->actorEmail,
            $existing->requestedByUserId,
            $existing->requestedBy,
            $existing->requestedBy,
        )) {
            throw CustomerContactReportApprovalFailure::selfApproval();
        }
        if ($existing->status !== 'pending') {
            throw CustomerContactReportApprovalFailure::notPending($existing->status);
        }

        $updated = new CustomerContactReportApproval(
            approvalId: $existing->approvalId,
            reportId: $existing->reportId,
            publicId: $existing->publicId,
            rowVersion: $this->nextRowVersion(),
            kind: $existing->kind,
            status: $decision->decision,
            approver: $existing->approver,
            approverUserId: $existing->approverUserId,
            approverName: $existing->approverName,
            approverEmail: $existing->approverEmail,
            requestedBy: $existing->requestedBy,
            requestedByUserId: $existing->requestedByUserId,
            requestedAtUtc: $existing->requestedAtUtc,
            note: $decision->note,
            decidedAtUtc: $decision->decidedAtUtc,
        );
        $this->storeApproval($updated);

        $settlement = null;
        if ($decision->kind === 'credit' && $decision->decision === 'approved') {
            $report = $report->withCreditReview(
                $this->nextRowVersion(),
                $existing->approverName,
                $decision->decidedAtUtc,
                $decision->decidedAtUtc,
            );
            $this->replace($index, $report);
            if ($decision->settlementApproverLogin !== null && $decision->settlementApproverUserId !== null && $decision->settlementApproverName !== null && $decision->settlementApproverEmail !== null) {
                $settlement = $this->ensureSettlement($report, $decision);
            }
        }

        return new CustomerContactReportApprovalOutcome($report, $updated, $settlement);
    }

    public function updateReport(CustomerContactReportUpdate $update): CustomerContactReportUpdateOutcome
    {
        $index = $this->indexOf($update->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($update->publicId);
        }
        $current = $this->reports[$index];
        $this->assertRowVersion($current, $update->expectedRowVersion);

        $changes = $update->changes;
        $creditApproval = null;
        $reset = false;
        if ($update->creditChanged) {
            $credit = $this->approvalFor($current->reportId, 'credit');
            $settlement = $this->approvalFor($current->reportId, 'settlement');
            if (($credit !== null && $credit->status !== 'pending') || $settlement !== null) {
                $reset = true;
                if ($credit !== null) {
                    $creditApproval = new CustomerContactReportApproval(
                        approvalId: $credit->approvalId,
                        reportId: $credit->reportId,
                        publicId: $credit->publicId,
                        rowVersion: $this->nextRowVersion(),
                        kind: 'credit',
                        status: 'pending',
                        approver: $credit->approver,
                        approverUserId: $credit->approverUserId,
                        approverName: $credit->approverName,
                        approverEmail: $credit->approverEmail,
                        requestedBy: $credit->requestedBy,
                        requestedByUserId: $credit->requestedByUserId,
                        requestedAtUtc: $update->updatedAtUtc,
                        note: null,
                        decidedAtUtc: null,
                    );
                    $this->storeApproval($creditApproval);
                }
                $this->approvals = array_values(array_filter(
                    $this->approvals,
                    static fn (CustomerContactReportApproval $a): bool => !($a->reportId === $current->reportId && $a->kind === 'settlement'),
                ));
                $changes['creditReviewedBy'] = null;
                $changes['creditReviewedDate'] = null;
            }
        }

        $updated = $current->withChanges($this->nextRowVersion(), $update->updatedAtUtc, $changes);
        $this->replace($index, $updated);

        return new CustomerContactReportUpdateOutcome($updated, $reset, $creditApproval);
    }

    public function approvalsFor(string $publicId): array
    {
        $index = $this->indexOf($publicId);
        if ($index === null) {
            return [];
        }
        $reportId = $this->reports[$index]->reportId;
        $matched = [];
        foreach ($this->approvals as $approval) {
            if ($approval->reportId === $reportId) {
                $matched[] = $approval;
            }
        }

        return $matched;
    }

    public function approvalSummaries(array $publicIds): array
    {
        $wanted = [];
        foreach ($publicIds as $publicId) {
            $wanted[strtolower($publicId)] = true;
        }
        $summaries = [];
        foreach ($this->approvals as $approval) {
            if (!isset($wanted[$approval->publicId])) {
                continue;
            }
            $summaries[$approval->publicId][] = ['kind' => $approval->kind, 'status' => $approval->status];
        }

        return $summaries;
    }

    public function attachmentsFor(string $publicId): array
    {
        $matched = [];
        $key = strtolower($publicId);
        foreach ($this->attachments as $attachment) {
            if ($attachment->publicId === $key) {
                $matched[] = $attachment;
            }
        }

        return $matched;
    }

    public function addEvidence(CustomerContactReportEvidence $evidence): CustomerContactReportStoredAttachment
    {
        $this->failFileMetadataIfRequested();
        $index = $this->indexOf($evidence->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($evidence->publicId);
        }
        $report = $this->reports[$index];
        if ($report->status !== CustomerContactReportStatus::SUBMITTED || $report->createdByUserId !== $evidence->createdByUserId) {
            throw CustomerContactReportFileFailure::evidenceNotAllowed('Photos can only be added by the person who raised a submitted Customer Contact.');
        }
        $count = 0;
        foreach ($this->attachments as $existing) {
            if ($existing->publicId === $report->publicId && $existing->kind === 'evidence') {
                $count++;
            }
        }
        if ($count >= 20) {
            throw CustomerContactReportFileFailure::invalid('This Customer Contact already has 20 attachments.');
        }

        $stored = new CustomerContactReportStoredAttachment(
            reportId: $report->reportId,
            publicId: $report->publicId,
            fileUrl: $evidence->fileUrl,
            fileName: $evidence->fileName,
            fileSize: $evidence->fileSize,
            contentType: $evidence->contentType,
            kind: 'evidence',
            createdByUserId: $evidence->createdByUserId,
            createdByEmail: $evidence->createdByEmail,
            createdAtUtc: $evidence->createdAtUtc,
        );
        $this->attachments[] = $stored;

        return $stored;
    }

    public function completeSettlement(CustomerContactReportCreditNote $creditNote): CustomerContactReportStoredAttachment
    {
        $this->failFileMetadataIfRequested();
        $index = $this->indexOf($creditNote->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($creditNote->publicId);
        }
        $report = $this->reports[$index];
        $credit = $this->approvalFor($report->reportId, 'credit');
        $settlement = $this->approvalFor($report->reportId, 'settlement');
        if ($credit === null || $credit->status !== 'approved' || $settlement === null) {
            throw CustomerContactReportFileFailure::creditNoteNotAllowed(
                'A credit note can be attached only after the credit is approved and Accounts has the step.',
            );
        }
        $expected = CustomerContactReportRowVersion::fromBase64($creditNote->expectedSettlementRowVersion);
        $actual = CustomerContactReportRowVersion::fromBase64($settlement->rowVersion);
        if (!$expected->equals($actual)) {
            throw CustomerContactReportApprovalFailure::conflict();
        }
        if (!CustomerContactReportAssigneeMatch::isCurrent(
            $creditNote->actorUserId,
            $creditNote->actorEmail,
            $settlement->approverUserId,
            $settlement->approver,
            $settlement->approverEmail,
        )) {
            throw CustomerContactReportFileFailure::settlementNotAssignee();
        }
        if ($settlement->status !== 'pending') {
            throw CustomerContactReportFileFailure::settlementNotPending();
        }

        $stored = new CustomerContactReportStoredAttachment(
            reportId: $report->reportId,
            publicId: $report->publicId,
            fileUrl: $creditNote->fileUrl,
            fileName: $creditNote->fileName,
            fileSize: $creditNote->fileSize,
            contentType: $creditNote->contentType,
            kind: 'credit_note',
            createdByUserId: $creditNote->actorUserId,
            createdByEmail: $creditNote->actorEmail,
            createdAtUtc: $creditNote->decidedAtUtc,
        );
        $this->attachments[] = $stored;
        $this->storeApproval(new CustomerContactReportApproval(
            approvalId: $settlement->approvalId,
            reportId: $settlement->reportId,
            publicId: $settlement->publicId,
            rowVersion: $this->nextRowVersion(),
            kind: 'settlement',
            status: 'approved',
            approver: $settlement->approver,
            approverUserId: $settlement->approverUserId,
            approverName: $settlement->approverName,
            approverEmail: $settlement->approverEmail,
            requestedBy: $settlement->requestedBy,
            requestedByUserId: $settlement->requestedByUserId,
            requestedAtUtc: $settlement->requestedAtUtc,
            note: $creditNote->note,
            decidedAtUtc: $creditNote->decidedAtUtc,
        ));
        if (in_array($report->status, [CustomerContactReportStatus::ASSIGNED, CustomerContactReportStatus::COMPLETED], true)) {
            $this->replace($index, $report->withClosure(
                rowVersion: $this->nextRowVersion(),
                closedBy: $settlement->approverName !== '' ? $settlement->approverName : $settlement->approver,
                closedByUserId: $creditNote->actorUserId,
                closedDate: $creditNote->decidedAtUtc,
                closedAtUtc: $creditNote->decidedAtUtc,
                updatedAtUtc: $creditNote->decidedAtUtc,
            ));
        }

        return $stored;
    }

    public function saveSignature(CustomerContactReportSignature $signature): CustomerContactReportDetails
    {
        $this->failFileMetadataIfRequested();
        $index = $this->indexOf($signature->publicId);
        if ($index === null) {
            throw CustomerContactReportNotFound::forPublicId($signature->publicId);
        }
        $report = $this->reports[$index];
        $this->assertRowVersion($report, $signature->expectedRowVersion);
        if ($report->route !== 'icar' || $report->status !== CustomerContactReportStatus::ASSIGNED) {
            throw CustomerContactReportFileFailure::signatureNotAllowed('A signature can be recorded only on an assigned ICAR Customer Contact.');
        }
        if (!CustomerContactReportAssigneeMatch::isCurrent(
            $signature->actorUserId,
            $signature->actorEmail,
            $report->assignedToUserId,
            $report->assignedTo,
            $report->assignedToEmail,
        )) {
            throw CustomerContactReportFileFailure::signatureNotAllowed('Only the current assignee can record the signature.');
        }
        if (trim((string) $report->icarSignatureUrl) !== '') {
            throw CustomerContactReportFileFailure::signatureExists();
        }

        $updated = $report->withIcarSignature(
            $this->nextRowVersion(),
            $signature->operatorName,
            $signature->signedAt,
            $signature->signatureUrl,
            $signature->signedAtUtc,
        );
        $this->replace($index, $updated);

        return $updated;
    }

    public function interruptNextFileMetadata(): void
    {
        $this->interruptFileMetadata = true;
    }

    public function numberSequenceRows(): int
    {
        return $this->sequences->committedYears();
    }

    public function lastAllocatedSequence(int $year): int
    {
        return $this->sequences->committedValue($year);
    }

    public function interruptNextAssignmentAfterSequenceAllocation(): void
    {
        $this->interruptAfterSequence = true;
    }

    public function seedCommittedSequence(int $year, int $lastSequence): void
    {
        $this->sequences->seedCommitted($year, $lastSequence);
    }

    /**
     * Fixture helper for tests. insert() is the only production create path
     * and it never allocates a CC number or a sequence row.
     */
    public function seedCopy(
        CustomerContactReportDetails $source,
        int $createdByUserId,
        string $createdByEmail,
        string $status = 'submitted',
        ?string $ccNumber = null,
        ?string $route = null,
        ?string $errorCode = null,
        ?string $assignedTo = null,
        ?string $description = null,
        ?string $customerCode = null,
        ?string $customerContact = null,
        ?string $productCode = null,
        ?string $location = null,
        ?DateTimeImmutable $reportDate = null,
        ?string $icarOperatorName = null,
    ): CustomerContactReportDetails {
        $copy = new CustomerContactReportDetails(
            reportId: $this->nextId++,
            publicId: CustomerContactReportPublicId::generate()->toString(),
            rowVersion: $this->nextRowVersion(),
            reportDate: $reportDate ?? $source->reportDate,
            location: $location ?? $source->location,
            customerCode: $customerCode ?? $source->customerCode,
            customerContact: $customerContact ?? $source->customerContact,
            tricabRefTrace: $source->tricabRefTrace,
            traceNum: $source->traceNum,
            conNoteNo: $source->conNoteNo,
            tricabContact: $source->tricabContact,
            productCode: $productCode ?? $source->productCode,
            description: $description ?? $source->description,
            status: $status,
            route: $route,
            ccNumber: $ccNumber,
            errorCode: $errorCode,
            assignedTo: $assignedTo,
            assignedToUserId: null,
            assignedToName: $assignedTo,
            assignedToEmail: $assignedTo,
            assignedBy: $assignedTo === null ? null : 'assigner@tricab.com',
            assignedAtUtc: $assignedTo === null ? null : $source->createdAtUtc,
            assigneeActionTaken: null,
            assigneeFindings: null,
            assigneeCompletedAtUtc: null,
            closedBy: $status === 'closed' ? 'closer@tricab.com' : null,
            closedByUserId: $status === 'closed' ? 1 : null,
            closedDate: $status === 'closed' ? $source->reportDate : null,
            closedAtUtc: $status === 'closed' ? $source->createdAtUtc : null,
            createdByUserId: $createdByUserId,
            createdByEmail: strtolower($createdByEmail),
            createdByName: $source->createdByName,
            createdAtUtc: $source->createdAtUtc,
            updatedAtUtc: $source->updatedAtUtc,
            investigationDueDate: null,
            ccAssessedBy: null,
            creditRequired: null,
            restockingFeeApplies: null,
            actionsTaken: null,
            schedulingInformed: null,
            returnArrangedBy: null,
            expectedReturnDate: null,
            goodsReturned: null,
            goodsReturnedDate: null,
            carrierBooked: null,
            carrierBookedDate: null,
            creditRaised: null,
            creditRaisedDate: null,
            goodsReplaced: null,
            goodsReplacedDate: null,
            carRaised: null,
            carRaisedDate: null,
            valueExGst: null,
            restockingAmount: null,
            subTotal: null,
            gst: null,
            creditTotal: null,
            rootCause: null,
            correctiveAction: null,
            notes: null,
            creditReviewedBy: null,
            creditReviewedDate: null,
            icarOperatorName: $icarOperatorName,
            icarSignedAt: $icarOperatorName === null ? null : '2026-09-01',
            icarSignatureUrl: $icarOperatorName === null ? null : 'https://example.invalid/signature.png',
        );
        $this->reports[] = $copy;

        return $copy;
    }

    /**
     * @param list<CustomerContactReportDetails> $reports
     */
    private function counts(array $reports): CustomerContactReportCounts
    {
        $counts = ['submitted' => 0, 'assigned' => 0, 'completed' => 0, 'closed' => 0];
        foreach ($reports as $report) {
            if (isset($counts[$report->status])) {
                $counts[$report->status]++;
            }
        }

        return new CustomerContactReportCounts($counts['submitted'], $counts['assigned'], $counts['completed'], $counts['closed']);
    }

    private function matches(CustomerContactReportDetails $report, CustomerContactReportListCriteria $criteria): bool
    {
        if ($criteria->year !== null && (int) $report->reportDate->format('Y') !== $criteria->year) {
            return false;
        }
        if ($criteria->month !== null && (int) $report->reportDate->format('n') !== $criteria->month) {
            return false;
        }
        if ($criteria->customer !== null && strcasecmp($report->customerCode, $criteria->customer) !== 0) {
            return false;
        }
        if ($criteria->assignee !== null && strcasecmp((string) $report->assignedTo, $criteria->assignee) !== 0) {
            return false;
        }
        if ($criteria->location !== null && strcasecmp($report->location, $criteria->location) !== 0) {
            return false;
        }
        if ($criteria->errorCode !== null && strcasecmp((string) $report->errorCode, $criteria->errorCode) !== 0) {
            return false;
        }
        if ($criteria->search !== null && !$this->searchMatches($report, $criteria->search)) {
            return false;
        }

        return true;
    }

    private function searchMatches(CustomerContactReportDetails $report, string $term): bool
    {
        foreach ([
            (string) $report->ccNumber,
            $report->customerCode,
            (string) $report->customerContact,
            $report->productCode,
            $report->description,
        ] as $field) {
            if (CustomerContactReportSearch::contains($field, $term)) {
                return true;
            }
        }

        return false;
    }

    private function compare(CustomerContactReportDetails $left, CustomerContactReportDetails $right): int
    {
        $rank = CustomerContactReportStatus::fromString($left->status)->rank()
            <=> CustomerContactReportStatus::fromString($right->status)->rank();
        if ($rank !== 0) {
            return $rank;
        }
        $date = $right->reportDate <=> $left->reportDate;
        if ($date !== 0) {
            return $date;
        }

        return $right->reportId <=> $left->reportId;
    }

    private function nextRowVersion(): string
    {
        return CustomerContactReportRowVersion::fromCounter($this->nextVersion++)->toBase64();
    }

    private function replace(int $index, CustomerContactReportDetails $report): void
    {
        $reports = $this->reports;
        $reports[$index] = $report;
        $this->reports = array_values($reports);
    }

    private function ensureSettlement(CustomerContactReportDetails $report, CustomerContactReportApprovalDecision $decision): CustomerContactReportApproval
    {
        $existing = $this->approvalFor($report->reportId, 'settlement');
        if ($existing !== null) {
            return $existing;
        }

        $settlement = new CustomerContactReportApproval(
            approvalId: $this->nextApprovalId++,
            reportId: $report->reportId,
            publicId: $report->publicId,
            rowVersion: $this->nextRowVersion(),
            kind: 'settlement',
            status: 'pending',
            approver: (string) $decision->settlementApproverLogin,
            approverUserId: $decision->settlementApproverUserId,
            approverName: (string) $decision->settlementApproverName,
            approverEmail: (string) $decision->settlementApproverEmail,
            requestedBy: $decision->actorEmail,
            requestedByUserId: $decision->actorUserId,
            requestedAtUtc: $decision->decidedAtUtc,
            note: null,
            decidedAtUtc: null,
        );
        $this->storeApproval($settlement);

        return $settlement;
    }

    private function storeApproval(CustomerContactReportApproval $approval): void
    {
        $approvals = $this->approvals;
        foreach ($approvals as $index => $existing) {
            if ($existing->reportId === $approval->reportId && $existing->kind === $approval->kind) {
                $approvals[$index] = $approval;
                $this->approvals = $approvals;

                return;
            }
        }
        $approvals[] = $approval;
        $this->approvals = $approvals;
    }

    private function failFileMetadataIfRequested(): void
    {
        if (!$this->interruptFileMetadata) {
            return;
        }
        $this->interruptFileMetadata = false;
        throw new RuntimeException('metadata write failed');
    }

    private function approvalFor(int $reportId, string $kind): ?CustomerContactReportApproval
    {
        foreach ($this->approvals as $approval) {
            if ($approval->reportId === $reportId && $approval->kind === $kind) {
                return $approval;
            }
        }

        return null;
    }

    /**
     * @return list<array{userId: ?int, login: string, email: string}>
     */
    private function approverRefs(int $reportId): array
    {
        $refs = [];
        foreach ($this->approvals as $approval) {
            if ($approval->reportId !== $reportId) {
                continue;
            }
            $refs[] = [
                'userId' => $approval->approverUserId,
                'login' => $approval->approver,
                'email' => $approval->approverEmail,
            ];
        }

        return $refs;
    }

    private function canSee(CustomerContactReportDetails $report, CustomerContactReportVisibility $visibility): bool
    {
        return $visibility->allows(
            $report->createdByUserId,
            $report->createdByEmail,
            $report->assignedToUserId,
            $report->assignedTo,
            $report->assignedToEmail,
            $this->approverRefs($report->reportId),
        );
    }

    private function assertRowVersion(CustomerContactReportDetails $current, string $expectedRowVersion): void
    {
        $expected = CustomerContactReportRowVersion::fromBase64($expectedRowVersion);
        $actual = CustomerContactReportRowVersion::fromBase64($current->rowVersion);
        if (!$expected->equals($actual)) {
            throw CustomerContactReportConflict::stale();
        }
    }

    private function indexOf(string $publicId): ?int
    {
        foreach ($this->reports as $index => $report) {
            if ($report->publicId === $publicId) {
                return $index;
            }
        }

        return null;
    }
}
