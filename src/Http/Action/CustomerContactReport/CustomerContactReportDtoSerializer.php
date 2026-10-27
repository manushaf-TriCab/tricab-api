<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CustomerContactReport;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCounts;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListResult;
use TriCab\Core\CustomerContactReport\Module;

final class CustomerContactReportDtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function created(CustomerContactReportDetails $report): array
    {
        return self::intake($report);
    }

    /**
     * A create or update response: the report plus warnings the screen should show.
     * A warning never means the save failed.
     *
     * @return array<string, mixed>
     */
    public static function saved(\TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSaveResult $result): array
    {
        $payload = self::intake($result->report);
        $payload['lines'] = array_map(self::line(...), $result->report->lines);
        $payload += self::creditFigures($result->report);
        $payload['warnings'] = $result->warnings;
        $payload['creditReset'] = $result->creditReset;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(\TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine $line): array
    {
        return [
            'orderNo' => $line->orderNo,
            'invoiceNo' => $line->invoiceNo,
            'lineNo' => $line->lineNo,
            'productCode' => $line->productCode,
            'description' => $line->description,
            'qty' => $line->qty,
            'unit' => $line->unit,
            'packs' => $line->packs,
            'packSize' => $line->packSize,
            'unitPrice' => $line->unitPrice,
            'net' => $line->net,
            'creditQty' => $line->creditQty,
            'creditRate' => $line->creditRate,
            'creditNet' => $line->creditNet,
            'gstRate' => $line->gstRate,
            'warehouse' => $line->warehouse,
            'comment' => $line->comment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listResult(CustomerContactReportListResult $result): array
    {
        return [
            'items' => array_map(
                static function (CustomerContactReportDetails $report) use ($result): array {
                    $item = self::intake($report);
                    $item['approvals'] = $result->approvalSummaries[$report->publicId] ?? [];

                    return $item;
                },
                $result->items,
            ),
            'pagination' => self::pagination($result->pagination),
            'counts' => self::counts($result->counts),
        ];
    }

    /**
     * Office and ICAR fields stay behind READ_ALL. Credit figures are also
     * visible to the credit approver. Assignee narrative is part of the
     * shared report.
     *
     * @param list<CustomerContactReportApproval> $approvals
     * @param list<CustomerContactReportStoredAttachment> $attachments
     * @return array<string, mixed>
     */
    public static function detail(
        CustomerContactReportDetails $report,
        bool $includeRestricted,
        array $approvals = [],
        ?int $viewerUserId = null,
        string $viewerEmail = '',
        array $attachments = [],
        bool $includeIcar = false,
    ): array {
        $payload = self::intake($report);
        $payload['approvals'] = array_map(self::approval(...), $approvals);
        $payload['attachments'] = array_map(self::attachment(...), $attachments);
        if ($includeIcar || $includeRestricted) {
            $payload += self::icar($report);
        }
        $payload['lines'] = array_map(self::line(...), $report->lines);
        $originator = $viewerEmail !== '' && strtolower(trim($report->createdByEmail)) === strtolower(trim($viewerEmail));
        if ($includeRestricted || $originator || self::viewsCreditFigures($approvals, $viewerUserId ?? 0, $viewerEmail)) {
            $payload += self::creditFigures($report);
        }
        if (!$includeRestricted) {
            return $payload;
        }

        return $payload + [
            'investigationDueDate' => $report->investigationDueDate,
            'ccAssessedBy' => $report->ccAssessedBy,
            'restockingFeeApplies' => $report->restockingFeeApplies,
            'actionsTaken' => $report->actionsTaken,
            'schedulingInformed' => $report->schedulingInformed,
            'returnArrangedBy' => $report->returnArrangedBy,
            'expectedReturnDate' => $report->expectedReturnDate,
            'goodsReturned' => $report->goodsReturned,
            'goodsReturnedDate' => $report->goodsReturnedDate,
            'carrierBooked' => $report->carrierBooked,
            'carrierBookedDate' => $report->carrierBookedDate,
            'creditRaised' => $report->creditRaised,
            'creditRaisedDate' => $report->creditRaisedDate,
            'goodsReplaced' => $report->goodsReplaced,
            'goodsReplacedDate' => $report->goodsReplacedDate,
            'carRaised' => $report->carRaised,
            'carRaisedDate' => $report->carRaisedDate,
            'valueExGst' => $report->valueExGst,
            'restockingAmount' => $report->restockingAmount,
            'subTotal' => $report->subTotal,
            'gst' => $report->gst,
            'creditTotal' => $report->creditTotal,
            'rootCause' => $report->rootCause,
            'correctiveAction' => $report->correctiveAction,
            'notes' => $report->notes,
            'creditReviewedBy' => $report->creditReviewedBy,
            'creditReviewedDate' => $report->creditReviewedDate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function intake(CustomerContactReportDetails $report): array
    {
        return [
            'publicId' => $report->publicId,
            'reportDate' => $report->reportDate->format('Y-m-d'),
            'location' => $report->location,
            'customerCode' => $report->customerCode,
            'customerContact' => $report->customerContact,
            'tricabRefTrace' => $report->tricabRefTrace,
            'traceNum' => $report->traceNum,
            'conNoteNo' => $report->conNoteNo,
            'tricabContact' => $report->tricabContact,
            'productCode' => $report->productCode,
            'description' => $report->description,
            'orderNo' => $report->orderNo,
            'invoiceNo' => $report->invoiceNo,
            'creditRequired' => $report->creditRequired,
            'creditType' => $report->creditType,
            'quotedAmount' => $report->quotedAmount,
            'invoicedAmount' => $report->invoicedAmount,
            'addGst' => $report->addGst,
            'requestedActions' => $report->requestedActions,
            'status' => $report->status,
            'route' => $report->route,
            'ccNumber' => $report->ccNumber,
            'errorCode' => $report->errorCode,
            'assignedTo' => $report->assignedTo,
            'assignedToName' => $report->assignedToName,
            'assignedToEmail' => $report->assignedToEmail,
            'assignedAtUtc' => self::instant($report->assignedAtUtc),
            'assigneeActionTaken' => $report->assigneeActionTaken,
            'assigneeFindings' => $report->assigneeFindings,
            'assigneeCompletedAtUtc' => self::instant($report->assigneeCompletedAtUtc),
            'closedBy' => $report->closedBy,
            'closedDate' => $report->closedDate?->format('Y-m-d'),
            'closedAtUtc' => self::instant($report->closedAtUtc),
            'createdByEmail' => $report->createdByEmail,
            'createdByName' => $report->createdByName,
            'createdAtUtc' => self::instant($report->createdAtUtc),
            'updatedAtUtc' => self::instant($report->updatedAtUtc),
            'assignedBy' => $report->assignedBy,
            'rowVersion' => $report->rowVersion,
        ];
    }

    /**
     * @return array<string, int|array<string, string>>
     */
    private static function counts(CustomerContactReportCounts $counts): array
    {
        return [
            'submitted' => $counts->submitted,
            'assigned' => $counts->assigned,
            'completed' => $counts->completed,
            'closed' => $counts->closed,
            'labels' => Module::STATUS_LABELS,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function pagination(Pagination $pagination): array
    {
        return [
            'page' => $pagination->page,
            'pageSize' => $pagination->pageSize,
            'totalItems' => $pagination->totalItems,
            'totalPages' => $pagination->totalPages,
        ];
    }

    /**
     * @param list<CustomerContactReportApproval> $approvals
     */
    private static function viewsCreditFigures(array $approvals, int $viewerUserId, string $viewerEmail): bool
    {
        foreach ($approvals as $approval) {
            if ($approval->kind !== 'credit') {
                continue;
            }
            if (CustomerContactReportAssigneeMatch::isCurrent(
                $viewerUserId,
                $viewerEmail,
                $approval->approverUserId,
                $approval->approver,
                $approval->approverEmail,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function approval(CustomerContactReportApproval $approval): array
    {
        return [
            'kind' => $approval->kind,
            'status' => $approval->status,
            'approver' => $approval->approver,
            'approverName' => $approval->approverName,
            'requestedBy' => $approval->requestedBy,
            'requestedAtUtc' => self::instant($approval->requestedAtUtc),
            'note' => $approval->note,
            'decidedAtUtc' => self::instant($approval->decidedAtUtc),
            'rowVersion' => $approval->rowVersion,
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private static function creditFigures(CustomerContactReportDetails $report): array
    {
        return [
            'valueExGst' => $report->valueExGst,
            'restockingAmount' => $report->restockingAmount,
            'subTotal' => $report->subTotal,
            'gst' => $report->gst,
            'creditTotal' => $report->creditTotal,
            'creditReviewedBy' => $report->creditReviewedBy,
            'creditReviewedDate' => $report->creditReviewedDate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(CustomerContactReportStoredAttachment $attachment): array
    {
        return [
            'fileName' => $attachment->fileName,
            'fileSize' => $attachment->fileSize,
            'contentType' => $attachment->contentType,
            'kind' => $attachment->kind,
            'createdBy' => $attachment->createdByEmail,
            'createdAtUtc' => self::instant($attachment->createdAtUtc),
            'fileUrl' => $attachment->fileUrl,
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private static function icar(CustomerContactReportDetails $report): array
    {
        return [
            'icarOperatorName' => $report->icarOperatorName,
            'icarSignedAt' => $report->icarSignedAt,
            'icarSignatureUrl' => $report->icarSignatureUrl,
        ];
    }

    private static function instant(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : AzureSqlValueMapper::formatIso8601($value);
    }
}
