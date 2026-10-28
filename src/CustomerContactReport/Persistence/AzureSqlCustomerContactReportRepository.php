<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCreditNote;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportEvidence;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSignature;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdate;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdateOutcome;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine;
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
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportApprovalSeparation;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportNumber;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSearch;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportStatus;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Azure SQL adapter for [cc_report].[Reports].
 *
 * Create inserts a submitted row and does not write [cc_report].[NumberSequences].
 */
final class AzureSqlCustomerContactReportRepository implements CustomerContactReportRepository
{
    use AzureSqlTransaction;

    private const COLUMNS = <<<'SQL'
    r.[ReportId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    r.[RowVersion] AS [RowVersion],
    CONVERT(VARCHAR(10), r.[ReportDate], 23) AS [ReportDate],
    r.[Location],
    r.[CustomerCode],
    r.[CustomerContact],
    r.[TricabRefTrace],
    r.[TraceNum],
    r.[ConNoteNo],
    r.[TricabContact],
    r.[ProductCode],
    r.[Description],
    r.[Status],
    r.[Route],
    r.[CcNumber],
    r.[ErrorCode],
    r.[AssignedTo],
    r.[AssignedToUserId],
    r.[AssignedToName],
    r.[AssignedToEmail],
    r.[AssignedBy],
    CONVERT(VARCHAR(33), r.[AssignedAtUtc], 127) AS [AssignedAtUtc],
    r.[AssigneeActionTaken],
    r.[AssigneeFindings],
    CONVERT(VARCHAR(33), r.[AssigneeCompletedAtUtc], 127) AS [AssigneeCompletedAtUtc],
    r.[ClosedBy],
    r.[ClosedByUserId],
    CONVERT(VARCHAR(10), r.[ClosedDate], 23) AS [ClosedDate],
    CONVERT(VARCHAR(33), r.[ClosedAtUtc], 127) AS [ClosedAtUtc],
    r.[CreatedByUserId],
    r.[CreatedByEmail],
    r.[CreatedByName],
    CONVERT(VARCHAR(33), r.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), r.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    CONVERT(VARCHAR(10), r.[InvestigationDueDate], 23) AS [InvestigationDueDate],
    r.[CcAssessedBy],
    r.[CreditRequired],
    r.[RestockingFeeApplies],
    r.[ActionsTaken],
    r.[SchedulingInformed],
    r.[ReturnArrangedBy],
    CONVERT(VARCHAR(10), r.[ExpectedReturnDate], 23) AS [ExpectedReturnDate],
    r.[GoodsReturned],
    CONVERT(VARCHAR(10), r.[GoodsReturnedDate], 23) AS [GoodsReturnedDate],
    r.[CarrierBooked],
    CONVERT(VARCHAR(10), r.[CarrierBookedDate], 23) AS [CarrierBookedDate],
    r.[CreditRaised],
    CONVERT(VARCHAR(10), r.[CreditRaisedDate], 23) AS [CreditRaisedDate],
    r.[GoodsReplaced],
    CONVERT(VARCHAR(10), r.[GoodsReplacedDate], 23) AS [GoodsReplacedDate],
    r.[CarRaised],
    CONVERT(VARCHAR(10), r.[CarRaisedDate], 23) AS [CarRaisedDate],
    CONVERT(VARCHAR(32), r.[ValueExGst]) AS [ValueExGst],
    CONVERT(VARCHAR(32), r.[RestockingAmount]) AS [RestockingAmount],
    CONVERT(VARCHAR(32), r.[SubTotal]) AS [SubTotal],
    CONVERT(VARCHAR(32), r.[Gst]) AS [Gst],
    CONVERT(VARCHAR(32), r.[CreditTotal]) AS [CreditTotal],
    r.[RootCause],
    r.[CorrectiveAction],
    r.[Notes],
    r.[CreditReviewedBy],
    CONVERT(VARCHAR(10), r.[CreditReviewedDate], 23) AS [CreditReviewedDate],
    r.[IcarOperatorName],
    CONVERT(VARCHAR(10), r.[IcarSignedAt], 23) AS [IcarSignedAt],
    r.[IcarSignatureUrl],
    r.[OrderNo],
    r.[InvoiceNo],
    r.[CreditType],
    r.[QuotedAmount],
    r.[InvoicedAmount],
    r.[AddGst],
    r.[RequestedActions]
SQL;

    /**
     * Details property => column for updateReport. Column names come only from
     * here, never from the request.
     *
     * @var array<string, array{0: string, 1: string}> property => [column, kind]
     */
    private const UPDATE_COLUMNS = [
        'reportDate' => ['ReportDate', 'date'],
        'location' => ['Location', 'text'],
        'customerCode' => ['CustomerCode', 'text'],
        'customerContact' => ['CustomerContact', 'text'],
        'tricabRefTrace' => ['TricabRefTrace', 'text'],
        'traceNum' => ['TraceNum', 'text'],
        'conNoteNo' => ['ConNoteNo', 'text'],
        'tricabContact' => ['TricabContact', 'text'],
        'productCode' => ['ProductCode', 'blankNull'],
        'description' => ['Description', 'text'],
        'requestedActions' => ['RequestedActions', 'text'],
        'orderNo' => ['OrderNo', 'text'],
        'invoiceNo' => ['InvoiceNo', 'text'],
        'creditType' => ['CreditType', 'text'],
        'quotedAmount' => ['QuotedAmount', 'text'],
        'invoicedAmount' => ['InvoicedAmount', 'text'],
        'addGst' => ['AddGst', 'text'],
        'creditRequired' => ['CreditRequired', 'text'],
        'restockingFeeApplies' => ['RestockingFeeApplies', 'text'],
        'valueExGst' => ['ValueExGst', 'text'],
        'restockingAmount' => ['RestockingAmount', 'text'],
        'subTotal' => ['SubTotal', 'text'],
        'gst' => ['Gst', 'text'],
        'creditTotal' => ['CreditTotal', 'text'],
        'schedulingInformed' => ['SchedulingInformed', 'text'],
        'returnArrangedBy' => ['ReturnArrangedBy', 'text'],
        'expectedReturnDate' => ['ExpectedReturnDate', 'text'],
        'goodsReturned' => ['GoodsReturned', 'text'],
        'goodsReturnedDate' => ['GoodsReturnedDate', 'text'],
        'carrierBooked' => ['CarrierBooked', 'text'],
        'carrierBookedDate' => ['CarrierBookedDate', 'text'],
        'creditRaised' => ['CreditRaised', 'text'],
        'creditRaisedDate' => ['CreditRaisedDate', 'text'],
        'goodsReplaced' => ['GoodsReplaced', 'text'],
        'goodsReplacedDate' => ['GoodsReplacedDate', 'text'],
        'carRaised' => ['CarRaised', 'text'],
        'carRaisedDate' => ['CarRaisedDate', 'text'],
        'actionsTaken' => ['ActionsTaken', 'text'],
        'rootCause' => ['RootCause', 'text'],
        'correctiveAction' => ['CorrectiveAction', 'text'],
        'notes' => ['Notes', 'text'],
        'errorCode' => ['ErrorCode', 'text'],
        'investigationDueDate' => ['InvestigationDueDate', 'text'],
        'ccAssessedBy' => ['CcAssessedBy', 'text'],
        'assigneeActionTaken' => ['AssigneeActionTaken', 'text'],
        'assigneeFindings' => ['AssigneeFindings', 'text'],
        'creditReviewedBy' => ['CreditReviewedBy', 'text'],
        'creditReviewedDate' => ['CreditReviewedDate', 'text'],
    ];

    private const APPROVAL_COLUMNS = <<<'SQL'
    a.[ApprovalId],
    a.[ReportId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    a.[RowVersion] AS [RowVersion],
    a.[Kind],
    a.[Status],
    a.[Approver],
    a.[ApproverUserId],
    a.[ApproverName],
    a.[ApproverEmail],
    a.[RequestedBy],
    a.[RequestedByUserId],
    CONVERT(VARCHAR(33), a.[RequestedAtUtc], 127) AS [RequestedAtUtc],
    a.[Note],
    CONVERT(VARCHAR(33), a.[DecidedAtUtc], 127) AS [DecidedAtUtc]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function insert(NewCustomerContactReport $report): CustomerContactReportDetails
    {
        $this->runInTransaction($this->connection, function () use ($report): void {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [cc_report].[Reports] (
    [PublicId],
    [CreatedByUserId],
    [CreatedByEmail],
    [CreatedByName],
    [CreatedAtUtc],
    [UpdatedAtUtc],
    [ReportDate],
    [Location],
    [CustomerCode],
    [CustomerContact],
    [TricabRefTrace],
    [TraceNum],
    [ConNoteNo],
    [TricabContact],
    [ProductCode],
    [Description],
    [OrderNo],
    [InvoiceNo],
    [CreditRequired],
    [CreditType],
    [RestockingFeeApplies],
    [QuotedAmount],
    [InvoicedAmount],
    [AddGst],
    [RequestedActions],
    [ValueExGst],
    [RestockingAmount],
    [SubTotal],
    [Gst],
    [CreditTotal]
)
VALUES (
    :publicId,
    :createdByUserId,
    :createdByEmail,
    :createdByName,
    :createdAtUtc,
    :updatedAtUtc,
    :reportDate,
    :location,
    :customerCode,
    :customerContact,
    :tricabRefTrace,
    :traceNum,
    :conNoteNo,
    :tricabContact,
    :productCode,
    :description,
    :orderNo,
    :invoiceNo,
    :creditRequired,
    :creditType,
    :restockingFeeApplies,
    :quotedAmount,
    :invoicedAmount,
    :addGst,
    :requestedActions,
    :valueExGst,
    :restockingAmount,
    :subTotal,
    :gst,
    :creditTotal
)
SQL,
                [
                    ':publicId' => $report->publicId,
                    ':createdByUserId' => $report->createdByUserId,
                    ':createdByEmail' => $report->createdByEmail,
                    ':createdByName' => $report->createdByName,
                    ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($report->createdAtUtc),
                    ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($report->createdAtUtc),
                    ':reportDate' => $report->reportDate->format('Y-m-d'),
                    ':location' => $report->location,
                    ':customerCode' => $report->customerCode,
                    ':customerContact' => $report->customerContact,
                    ':tricabRefTrace' => $report->tricabRefTrace,
                    ':traceNum' => $report->traceNum,
                    ':conNoteNo' => $report->conNoteNo,
                    ':tricabContact' => $report->tricabContact,
                    ':productCode' => $report->productCode === '' ? null : $report->productCode,
                    ':description' => $report->description,
                    ':orderNo' => $report->orderNo,
                    ':invoiceNo' => $report->invoiceNo,
                    ':creditRequired' => $report->creditRequired,
                    ':creditType' => $report->creditType,
                    ':restockingFeeApplies' => $report->restockingFeeApplies,
                    ':quotedAmount' => $report->quotedAmount,
                    ':invoicedAmount' => $report->invoicedAmount,
                    ':addGst' => $report->addGst,
                    ':requestedActions' => $report->requestedActions,
                    ':valueExGst' => $report->figures?->valueExGst,
                    ':restockingAmount' => $report->figures?->restockingAmount,
                    ':subTotal' => $report->figures?->subTotal,
                    ':gst' => $report->figures?->gst,
                    ':creditTotal' => $report->figures?->creditTotal,
                ],
            );
            if ($report->lines !== []) {
                $reportId = AzureSqlValueMapper::intValue($this->connection->fetchAll(
                    'SELECT [ReportId] FROM [cc_report].[Reports] WHERE [PublicId] = :publicId',
                    [':publicId' => $report->publicId],
                )[0]['ReportId'] ?? 0);
                $this->insertLines($reportId, $report->lines);
            }
        });

        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . '
FROM [cc_report].[Reports] r
WHERE r.[PublicId] = :publicId',
            [':publicId' => $report->publicId],
        );
        if ($rows === []) {
            throw new \RuntimeException('Customer Contact report was inserted but could not be reloaded.');
        }

        return $this->withLines($this->hydrate($rows[0]));
    }

    public function findVisible(
        CustomerContactReportPublicId $publicId,
        CustomerContactReportVisibility $visibility,
    ): ?CustomerContactReportDetails {
        [$visibilitySql, $params] = $this->visibility($visibility);
        $params[':publicId'] = $publicId->toString();
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . '
FROM [cc_report].[Reports] r
WHERE r.[PublicId] = :publicId
  AND ' . $visibilitySql,
            $params,
        );

        return $rows === [] ? null : $this->withLines($this->hydrate($rows[0]));
    }

    public function list(
        CustomerContactReportListCriteria $criteria,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportListResult {
        [$whereSql, $params] = $this->filters($criteria->withoutStatus(), $visibility);
        $countRows = $this->connection->fetchAll(
            'SELECT r.[Status], COUNT(1) AS [Total]
FROM [cc_report].[Reports] r
' . $whereSql . '
GROUP BY r.[Status]',
            $params,
        );

        [$pageSql, $pageParams] = $this->filters($criteria, $visibility);
        $totalRows = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Total] FROM [cc_report].[Reports] r ' . $pageSql,
            $pageParams,
        );
        $total = AzureSqlValueMapper::intValue($totalRows[0]['Total'] ?? 0);
        $pageParams[':offset'] = ($criteria->page - 1) * $criteria->pageSize;
        $pageParams[':pageSize'] = $criteria->pageSize;
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ',
(
    SELECT STRING_AGG(CONCAT(a.[Kind], N\':\', a.[Status]), N\',\')
    FROM [cc_report].[Approvals] a
    WHERE a.[ReportId] = r.[ReportId]
) AS [ApprovalSummary]
FROM [cc_report].[Reports] r
' . $pageSql . '
ORDER BY CASE r.[Status]
    WHEN N\'submitted\' THEN 0
    WHEN N\'assigned\' THEN 1
    WHEN N\'completed\' THEN 2
    WHEN N\'closed\' THEN 3
    ELSE 4
END, r.[ReportDate] DESC, r.[ReportId] DESC
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY',
            $pageParams,
        );

        $items = [];
        $summaries = [];
        foreach ($rows as $row) {
            $item = $this->hydrate($row);
            $items[] = $item;
            $summaries[$item->publicId] = self::parseApprovalSummary($row['ApprovalSummary'] ?? null);
        }

        return new CustomerContactReportListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
            counts: $this->counts($countRows),
            approvalSummaries: $summaries,
        );
    }

    public function assign(CustomerContactReportAssignment $assignment): CustomerContactReportDetails
    {
        return $this->runInTransaction($this->connection, function () use ($assignment): CustomerContactReportDetails {
            $locked = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    r.[Status],
    r.[RowVersion],
    r.[CcNumber],
    r.[Location],
    CONVERT(VARCHAR(10), r.[ReportDate], 23) AS [ReportDate]
FROM [cc_report].[Reports] r WITH (UPDLOCK, HOLDLOCK)
WHERE r.[PublicId] = :publicId
SQL,
                [':publicId' => $assignment->publicId],
            );
            if ($locked === []) {
                throw CustomerContactReportNotFound::forPublicId($assignment->publicId);
            }

            $row = $locked[0];
            $currentVersion = ProductRowVersionMapper::toToken($row['RowVersion'] ?? null);
            $expected = CustomerContactReportRowVersion::fromBase64($assignment->expectedRowVersion);
            if (!hash_equals($currentVersion->toBinary(), $expected->toBinary())) {
                throw CustomerContactReportConflict::stale();
            }

            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if ($status !== CustomerContactReportStatus::SUBMITTED && $status !== CustomerContactReportStatus::ASSIGNED) {
                throw CustomerContactReportConflict::notAssignable($status);
            }

            $ccNumber = AzureSqlValueMapper::nullableString($row['CcNumber'] ?? null);
            if ($status === CustomerContactReportStatus::SUBMITTED) {
                $reportDate = self::date(AzureSqlValueMapper::stringValue($row['ReportDate'] ?? ''), 'ReportDate');
                $sequence = $this->allocateSequence((int) $reportDate->format('Y'), $assignment->assignedAtUtc);
                $ccNumber = CustomerContactReportNumber::format(
                    $reportDate,
                    $sequence,
                    AzureSqlValueMapper::stringValue($row['Location'] ?? ''),
                    $assignment->errorCode,
                );
            }

            $updated = $this->connection->fetchAll(
                <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [Status] = :status,
    [Route] = :route,
    [ErrorCode] = :errorCode,
    [CcNumber] = :ccNumber,
    [AssignedTo] = :assignedTo,
    [AssignedToUserId] = :assignedToUserId,
    [AssignedToName] = :assignedToName,
    [AssignedToEmail] = :assignedToEmail,
    [AssignedBy] = :assignedBy,
    [AssignedAtUtc] = :assignedAtUtc,
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion
SQL,
                [
                    ':status' => CustomerContactReportStatus::ASSIGNED,
                    ':route' => $assignment->route,
                    ':errorCode' => $assignment->errorCode,
                    ':ccNumber' => $ccNumber,
                    ':assignedTo' => $assignment->assigneeLogin,
                    ':assignedToUserId' => $assignment->assigneeUserId,
                    ':assignedToName' => $assignment->assigneeName,
                    ':assignedToEmail' => $assignment->assigneeEmail,
                    ':assignedBy' => $assignment->assignedByEmail,
                    ':assignedAtUtc' => AzureSqlValueMapper::formatDateTime($assignment->assignedAtUtc),
                    ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($assignment->assignedAtUtc),
                    ':publicId' => $assignment->publicId,
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                        RowVersionToken::fromBinary($expected->toBinary()),
                    ),
                ],
            );
            if ($updated === []) {
                throw CustomerContactReportConflict::stale();
            }
            // A pending credit follows the reassignment: the assignee owns it.
            $this->connection->execute(
                <<<'SQL'
UPDATE a
SET a.[Approver] = :approver, a.[ApproverUserId] = :approverUserId, a.[ApproverName] = :approverName,
    a.[ApproverEmail] = :approverEmail, a.[UpdatedAtUtc] = :updatedAtUtc
FROM [cc_report].[Approvals] a
JOIN [cc_report].[Reports] r ON r.[ReportId] = a.[ReportId]
WHERE r.[PublicId] = :publicId AND a.[Kind] = N'credit' AND a.[Status] = N'pending'
SQL,
                [
                    ':approver' => strtolower($assignment->assigneeLogin),
                    ':approverUserId' => $assignment->assigneeUserId,
                    ':approverName' => $assignment->assigneeName,
                    ':approverEmail' => $assignment->assigneeEmail,
                    ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($assignment->assignedAtUtc),
                    ':publicId' => $assignment->publicId,
                ],
            );

            $rows = $this->connection->fetchAll(
                'SELECT ' . self::COLUMNS . '
FROM [cc_report].[Reports] r
WHERE r.[PublicId] = :publicId',
                [':publicId' => $assignment->publicId],
            );
            if ($rows === []) {
                throw CustomerContactReportNotFound::forPublicId($assignment->publicId);
            }

            return $this->hydrate($rows[0]);
        });
    }

    public function complete(CustomerContactReportCompletion $completion): CustomerContactReportDetails
    {
        return $this->runInTransaction($this->connection, function () use ($completion): CustomerContactReportDetails {
            $row = $this->lockReport($completion->publicId, $completion->expectedRowVersion);
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if ($status !== CustomerContactReportStatus::ASSIGNED) {
                throw CustomerContactReportConflict::notCompletable($status);
            }
            if (!CustomerContactReportAssigneeMatch::isCurrent(
                $completion->actorUserId,
                $completion->actorEmail,
                AzureSqlValueMapper::nullableInt($row['AssignedToUserId'] ?? null),
                AzureSqlValueMapper::nullableString($row['AssignedTo'] ?? null),
                AzureSqlValueMapper::nullableString($row['AssignedToEmail'] ?? null),
            )) {
                throw new CustomerContactReportNotAssignee();
            }

            $action = $completion->actionProvided
                ? $completion->assigneeActionTaken
                : AzureSqlValueMapper::nullableString($row['AssigneeActionTaken'] ?? null);
            $findings = $completion->findingsProvided
                ? $completion->assigneeFindings
                : AzureSqlValueMapper::nullableString($row['AssigneeFindings'] ?? null);
            $this->updateWorkflow($completion->publicId, $completion->expectedRowVersion, [
                ':status' => CustomerContactReportStatus::COMPLETED,
                ':assigneeActionTaken' => $action,
                ':assigneeFindings' => $findings,
                ':assigneeCompletedAtUtc' => AzureSqlValueMapper::formatDateTime($completion->completedAtUtc),
                ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($completion->completedAtUtc),
            ], <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [Status] = :status,
    [AssigneeActionTaken] = :assigneeActionTaken,
    [AssigneeFindings] = :assigneeFindings,
    [AssigneeCompletedAtUtc] = :assigneeCompletedAtUtc,
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion
SQL);

            return $this->reload($completion->publicId);
        });
    }

    public function close(CustomerContactReportClosure $closure): CustomerContactReportDetails
    {
        return $this->runInTransaction($this->connection, function () use ($closure): CustomerContactReportDetails {
            $row = $this->lockReport($closure->publicId, $closure->expectedRowVersion);
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if ($status !== CustomerContactReportStatus::COMPLETED) {
                throw CustomerContactReportConflict::notClosable($status);
            }

            $this->updateWorkflow($closure->publicId, $closure->expectedRowVersion, [
                ':status' => CustomerContactReportStatus::CLOSED,
                ':closedBy' => $closure->closedBy,
                ':closedByUserId' => $closure->closedByUserId,
                ':closedDate' => $closure->closedDate->format('Y-m-d'),
                ':closedAtUtc' => AzureSqlValueMapper::formatDateTime($closure->closedAtUtc),
                ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($closure->closedAtUtc),
            ], <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [Status] = :status,
    [ClosedBy] = :closedBy,
    [ClosedByUserId] = :closedByUserId,
    [ClosedDate] = :closedDate,
    [ClosedAtUtc] = :closedAtUtc,
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion
SQL);

            return $this->reload($closure->publicId);
        });
    }

    public function reopen(CustomerContactReportReopen $reopen): CustomerContactReportDetails
    {
        return $this->runInTransaction($this->connection, function () use ($reopen): CustomerContactReportDetails {
            $row = $this->lockReport($reopen->publicId, $reopen->expectedRowVersion);
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if ($status !== CustomerContactReportStatus::COMPLETED && $status !== CustomerContactReportStatus::CLOSED) {
                throw CustomerContactReportConflict::notReopenable($status);
            }
            $assignedTo = AzureSqlValueMapper::nullableString($row['AssignedTo'] ?? null);
            if ($assignedTo === null) {
                throw CustomerContactReportConflict::notReopenable($status);
            }

            $signature = AzureSqlValueMapper::nullableString($row['IcarSignatureUrl'] ?? null);
            if ($signature !== null) {
                $signedAt = AzureSqlValueMapper::nullableString($row['IcarSignedAt'] ?? null);
                $fileName = 'signature-superseded-' . ($signedAt ?? $reopen->reopenedAtUtc->format('Y-m-d')) . '.png';
                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [cc_report].[Attachments] (
    [ReportId], [FileUrl], [FileName], [FileSize], [ContentType], [Kind], [CreatedAtUtc]
) VALUES (
    :reportId, :fileUrl, :fileName, 0, N'image/png', N'signature_superseded', :createdAtUtc
)
SQL,
                    [
                        ':reportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                        ':fileUrl' => $signature,
                        ':fileName' => $fileName,
                        ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($reopen->reopenedAtUtc),
                    ],
                );
            }

            $clearSignature = $signature !== null ? 1 : 0;
            $this->updateWorkflow($reopen->publicId, $reopen->expectedRowVersion, [
                ':status' => CustomerContactReportStatus::ASSIGNED,
                ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($reopen->reopenedAtUtc),
                ':clearOperator' => $clearSignature,
                ':clearSignedAt' => $clearSignature,
                ':clearUrl' => $clearSignature,
            ], <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [Status] = :status,
    [AssigneeCompletedAtUtc] = NULL,
    [ClosedAtUtc] = NULL,
    [IcarOperatorName] = CASE WHEN :clearOperator = 1 THEN NULL ELSE [IcarOperatorName] END,
    [IcarSignedAt] = CASE WHEN :clearSignedAt = 1 THEN NULL ELSE [IcarSignedAt] END,
    [IcarSignatureUrl] = CASE WHEN :clearUrl = 1 THEN NULL ELSE [IcarSignatureUrl] END,
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion
  AND [Status] IN (N'completed', N'closed')
  AND [AssignedTo] IS NOT NULL
SQL);

            return $this->reload($reopen->publicId);
        });
    }

    /**
     * @return list<CustomerContactReportStoredAttachment>
     */
    public function signatureSupersessions(string $publicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    a.[ReportId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    a.[FileUrl],
    a.[FileName],
    a.[FileSize],
    a.[ContentType],
    a.[Kind]
FROM [cc_report].[Attachments] a
JOIN [cc_report].[Reports] r ON r.[ReportId] = a.[ReportId]
WHERE r.[PublicId] = :publicId
  AND a.[Kind] = N'signature_superseded'
ORDER BY a.[AttachmentId]
SQL,
            [':publicId' => strtolower($publicId)],
        );

        $attachments = [];
        foreach ($rows as $row) {
            $attachments[] = new CustomerContactReportStoredAttachment(
                reportId: AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                publicId: strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
                fileUrl: AzureSqlValueMapper::stringValue($row['FileUrl'] ?? ''),
                fileName: AzureSqlValueMapper::stringValue($row['FileName'] ?? ''),
                fileSize: AzureSqlValueMapper::intValue($row['FileSize'] ?? 0),
                contentType: AzureSqlValueMapper::stringValue($row['ContentType'] ?? ''),
                kind: AzureSqlValueMapper::stringValue($row['Kind'] ?? ''),
            );
        }

        return $attachments;
    }

    public function requestApproval(CustomerContactReportApprovalRequest $request): CustomerContactReportApprovalOutcome
    {
        return $this->runInTransaction($this->connection, function () use ($request): CustomerContactReportApprovalOutcome {
            $locked = $this->lockReport($request->publicId, $request->expectedReportRowVersion);
            $reportId = AzureSqlValueMapper::intValue($locked['ReportId'] ?? 0);
            $existing = $this->connection->fetchAll(
                <<<'SQL'
SELECT [ApprovalId], [Status]
FROM [cc_report].[Approvals] WITH (UPDLOCK, HOLDLOCK)
WHERE [ReportId] = :reportId AND [Kind] = :kind
SQL,
                [':reportId' => $reportId, ':kind' => $request->kind],
            );
            $row = $existing[0] ?? null;
            if (is_array($row)) {
                $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
                if ($status !== 'pending') {
                    throw CustomerContactReportApprovalFailure::notPending($status);
                }
            }

            if ($request->writeCreditFigures) {
                $this->updateWorkflow($request->publicId, $request->expectedReportRowVersion, [
                    ':valueExGst' => $request->valueExGst,
                    ':restockingAmount' => $request->restockingAmount,
                    ':subTotal' => $request->subTotal,
                    ':gst' => $request->gst,
                    ':creditTotal' => $request->creditTotal,
                    ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($request->requestedAtUtc),
                ], <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [ValueExGst] = COALESCE(:valueExGst, [ValueExGst]),
    [RestockingAmount] = COALESCE(:restockingAmount, [RestockingAmount]),
    [SubTotal] = COALESCE(:subTotal, [SubTotal]),
    [Gst] = COALESCE(:gst, [Gst]),
    [CreditTotal] = COALESCE(:creditTotal, [CreditTotal]),
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion
SQL);
            }

            $stamp = AzureSqlValueMapper::formatDateTime($request->requestedAtUtc);
            if (!is_array($row)) {
                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [cc_report].[Approvals] (
    [ReportId], [Kind], [Approver], [ApproverUserId], [ApproverName], [ApproverEmail],
    [Status], [RequestedBy], [RequestedByUserId], [RequestedAtUtc], [UpdatedAtUtc]
) VALUES (
    :reportId, :kind, :approver, :approverUserId, :approverName, :approverEmail,
    N'pending', :requestedBy, :requestedByUserId, :requestedAtUtc, :updatedAtUtc
)
SQL,
                    [
                        ':reportId' => $reportId,
                        ':kind' => $request->kind,
                        ':approver' => $request->approverLogin,
                        ':approverUserId' => $request->approverUserId,
                        ':approverName' => $request->approverName,
                        ':approverEmail' => $request->approverEmail,
                        ':requestedBy' => $request->requestedBy,
                        ':requestedByUserId' => $request->requestedByUserId,
                        ':requestedAtUtc' => $stamp,
                        ':updatedAtUtc' => $stamp,
                    ],
                );
            } else {
                $this->connection->execute(
                    <<<'SQL'
UPDATE [cc_report].[Approvals]
SET
    [Approver] = :approver,
    [ApproverUserId] = :approverUserId,
    [ApproverName] = :approverName,
    [ApproverEmail] = :approverEmail,
    [RequestedBy] = :requestedBy,
    [RequestedByUserId] = :requestedByUserId,
    [RequestedAtUtc] = :requestedAtUtc,
    [UpdatedAtUtc] = :updatedAtUtc
WHERE [ReportId] = :reportId AND [Kind] = :kind AND [Status] = N'pending'
SQL,
                    [
                        ':approver' => $request->approverLogin,
                        ':approverUserId' => $request->approverUserId,
                        ':approverName' => $request->approverName,
                        ':approverEmail' => $request->approverEmail,
                        ':requestedBy' => $request->requestedBy,
                        ':requestedByUserId' => $request->requestedByUserId,
                        ':requestedAtUtc' => $stamp,
                        ':updatedAtUtc' => $stamp,
                        ':reportId' => $reportId,
                        ':kind' => $request->kind,
                    ],
                );
            }

            return new CustomerContactReportApprovalOutcome(
                $this->reload($request->publicId),
                $this->approvalByKind($request->publicId, $request->kind),
                null,
            );
        });
    }

    public function decideApproval(CustomerContactReportApprovalDecision $decision): CustomerContactReportApprovalOutcome
    {
        return $this->runInTransaction($this->connection, function () use ($decision): CustomerContactReportApprovalOutcome {
            $locked = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    a.[ApprovalId],
    a.[ReportId],
    a.[Status],
    a.[RowVersion],
    a.[Approver],
    a.[ApproverUserId],
    a.[ApproverEmail],
    a.[ApproverName],
    a.[RequestedBy],
    a.[RequestedByUserId]
FROM [cc_report].[Approvals] a WITH (UPDLOCK, HOLDLOCK)
JOIN [cc_report].[Reports] r WITH (UPDLOCK, HOLDLOCK) ON r.[ReportId] = a.[ReportId]
WHERE r.[PublicId] = :publicId AND a.[Kind] = :kind
SQL,
                [':publicId' => $decision->publicId, ':kind' => $decision->kind],
            );
            $row = $locked[0] ?? null;
            if (!is_array($row)) {
                throw CustomerContactReportApprovalFailure::notFound($decision->kind);
            }
            $currentVersion = ProductRowVersionMapper::toToken($row['RowVersion'] ?? null);
            $expected = CustomerContactReportRowVersion::fromBase64($decision->expectedRowVersion);
            if (!hash_equals($currentVersion->toBinary(), $expected->toBinary())) {
                throw CustomerContactReportApprovalFailure::conflict();
            }
            if (!CustomerContactReportAssigneeMatch::isCurrent(
                $decision->actorUserId,
                $decision->actorEmail,
                AzureSqlValueMapper::nullableInt($row['ApproverUserId'] ?? null),
                AzureSqlValueMapper::nullableString($row['Approver'] ?? null),
                AzureSqlValueMapper::nullableString($row['ApproverEmail'] ?? null),
            )) {
                throw CustomerContactReportApprovalFailure::notApprover();
            }
            if (CustomerContactReportApprovalSeparation::samePerson(
                $decision->actorUserId,
                $decision->actorEmail,
                AzureSqlValueMapper::nullableInt($row['RequestedByUserId'] ?? null),
                AzureSqlValueMapper::stringValue($row['RequestedBy'] ?? ''),
                AzureSqlValueMapper::stringValue($row['RequestedBy'] ?? ''),
            )) {
                throw CustomerContactReportApprovalFailure::selfApproval();
            }
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if ($status !== 'pending') {
                throw CustomerContactReportApprovalFailure::notPending($status);
            }

            $this->connection->execute(
                <<<'SQL'
UPDATE [cc_report].[Approvals]
SET
    [Status] = :decision,
    [Note] = :note,
    [DecidedAtUtc] = :decidedAtUtc,
    [UpdatedAtUtc] = :updatedAtUtc
WHERE [ApprovalId] = :approvalId AND [Status] = N'pending'
SQL,
                [
                    ':decision' => $decision->decision,
                    ':note' => $decision->note,
                    ':decidedAtUtc' => AzureSqlValueMapper::formatDateTime($decision->decidedAtUtc),
                    ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($decision->decidedAtUtc),
                    ':approvalId' => AzureSqlValueMapper::intValue($row['ApprovalId'] ?? 0),
                ],
            );

            if ($decision->kind === 'credit' && $decision->decision === 'approved') {
                $this->connection->execute(
                    <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [CreditReviewedBy] = :creditReviewedBy,
    [CreditReviewedDate] = :creditReviewedDate,
    [UpdatedAtUtc] = :updatedAtUtc
WHERE [ReportId] = :reportId
SQL,
                    [
                        ':creditReviewedBy' => AzureSqlValueMapper::stringValue($row['ApproverName'] ?? ''),
                        ':creditReviewedDate' => $decision->decidedAtUtc->format('Y-m-d'),
                        ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($decision->decidedAtUtc),
                        ':reportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                    ],
                );
                if ($decision->settlementApproverLogin !== null) {
                    $this->connection->execute(
                        <<<'SQL'
IF NOT EXISTS (
    SELECT 1 FROM [cc_report].[Approvals]
    WHERE [ReportId] = :reportId AND [Kind] = N'settlement'
)
INSERT INTO [cc_report].[Approvals] (
    [ReportId], [Kind], [Approver], [ApproverUserId], [ApproverName], [ApproverEmail],
    [Status], [RequestedBy], [RequestedByUserId], [RequestedAtUtc], [UpdatedAtUtc]
) VALUES (
    :insertReportId, N'settlement', :approver, :approverUserId, :approverName, :approverEmail,
    N'pending', :requestedBy, :requestedByUserId, :requestedAtUtc, :updatedAtUtc
)
SQL,
                        [
                            ':reportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                            ':insertReportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                            ':approver' => $decision->settlementApproverLogin,
                            ':approverUserId' => $decision->settlementApproverUserId,
                            ':approverName' => $decision->settlementApproverName,
                            ':approverEmail' => $decision->settlementApproverEmail,
                            ':requestedBy' => $decision->actorEmail,
                            ':requestedByUserId' => $decision->actorUserId,
                            ':requestedAtUtc' => AzureSqlValueMapper::formatDateTime($decision->decidedAtUtc),
                            ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($decision->decidedAtUtc),
                        ],
                    );
                }
            }

            $settlement = null;
            if ($decision->kind === 'credit' && $decision->decision === 'approved' && $decision->settlementApproverLogin !== null) {
                $settlement = $this->approvalByKind($decision->publicId, 'settlement');
            }

            return new CustomerContactReportApprovalOutcome(
                $this->reload($decision->publicId),
                $this->approvalByKind($decision->publicId, $decision->kind),
                $settlement,
            );
        });
    }

    public function approvalsFor(string $publicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::APPROVAL_COLUMNS . '
FROM [cc_report].[Approvals] a
JOIN [cc_report].[Reports] r ON r.[ReportId] = a.[ReportId]
WHERE r.[PublicId] = :publicId
ORDER BY a.[ApprovalId]',
            [':publicId' => strtolower($publicId)],
        );
        $approvals = [];
        foreach ($rows as $row) {
            $approvals[] = $this->hydrateApproval($row);
        }

        return $approvals;
    }

    public function approvalSummaries(array $publicIds): array
    {
        unset($publicIds);

        return [];
    }

    public function attachmentsFor(string $publicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    a.[ReportId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    a.[FileUrl],
    a.[FileName],
    a.[FileSize],
    a.[ContentType],
    a.[Kind],
    a.[CreatedByUserId],
    a.[CreatedByEmail],
    CONVERT(VARCHAR(33), a.[CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [cc_report].[Attachments] a
JOIN [cc_report].[Reports] r ON r.[ReportId] = a.[ReportId]
WHERE r.[PublicId] = :publicId
ORDER BY a.[AttachmentId]
SQL,
            [':publicId' => strtolower($publicId)],
        );
        $attachments = [];
        foreach ($rows as $row) {
            $attachments[] = $this->hydrateAttachment($row);
        }

        return $attachments;
    }

    public function addEvidence(CustomerContactReportEvidence $evidence): CustomerContactReportStoredAttachment
    {
        return $this->runInTransaction($this->connection, function () use ($evidence): CustomerContactReportStoredAttachment {
            $locked = $this->connection->fetchAll(
                <<<'SQL'
SELECT r.[ReportId], r.[Status], r.[CreatedByUserId],
       (SELECT COUNT(*) FROM [cc_report].[Attachments] e
         WHERE e.[ReportId] = r.[ReportId] AND e.[Kind] = N'evidence') AS [EvidenceCount]
FROM [cc_report].[Reports] r WITH (UPDLOCK, HOLDLOCK)
WHERE r.[PublicId] = :publicId
SQL,
                [':publicId' => $evidence->publicId],
            );
            $row = $locked[0] ?? null;
            if (!is_array($row)) {
                throw CustomerContactReportNotFound::forPublicId($evidence->publicId);
            }
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            $creator = AzureSqlValueMapper::intValue($row['CreatedByUserId'] ?? 0);
            if ($status !== 'submitted' || $creator !== $evidence->createdByUserId) {
                throw CustomerContactReportFileFailure::evidenceNotAllowed('Photos can only be added by the person who raised a submitted Customer Contact.');
            }
            if (AzureSqlValueMapper::intValue($row['EvidenceCount'] ?? 0) >= 20) {
                throw CustomerContactReportFileFailure::invalid('This Customer Contact already has 20 attachments.');
            }
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [cc_report].[Attachments] (
    [ReportId], [FileUrl], [FileName], [FileSize], [ContentType], [Kind],
    [CreatedByUserId], [CreatedByEmail], [CreatedAtUtc]
) VALUES (
    :reportId, :fileUrl, :fileName, :fileSize, :contentType, N'evidence',
    :createdByUserId, :createdByEmail, :createdAtUtc
)
SQL,
                [
                    ':reportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                    ':fileUrl' => $evidence->fileUrl,
                    ':fileName' => $evidence->fileName,
                    ':fileSize' => $evidence->fileSize,
                    ':contentType' => $evidence->contentType,
                    ':createdByUserId' => $evidence->createdByUserId,
                    ':createdByEmail' => $evidence->createdByEmail,
                    ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($evidence->createdAtUtc),
                ],
            );

            return new CustomerContactReportStoredAttachment(
                reportId: AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                publicId: $evidence->publicId,
                fileUrl: $evidence->fileUrl,
                fileName: $evidence->fileName,
                fileSize: $evidence->fileSize,
                contentType: $evidence->contentType,
                kind: 'evidence',
                createdByUserId: $evidence->createdByUserId,
                createdByEmail: $evidence->createdByEmail,
                createdAtUtc: $evidence->createdAtUtc,
            );
        });
    }

    public function completeSettlement(CustomerContactReportCreditNote $creditNote): CustomerContactReportStoredAttachment
    {
        return $this->runInTransaction($this->connection, function () use ($creditNote): CustomerContactReportStoredAttachment {
            $locked = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    s.[ApprovalId],
    s.[ReportId],
    s.[Status],
    s.[RowVersion],
    s.[Approver],
    s.[ApproverUserId],
    s.[ApproverEmail],
    s.[ApproverName],
    c.[Status] AS [CreditStatus]
FROM [cc_report].[Approvals] s WITH (UPDLOCK, HOLDLOCK)
JOIN [cc_report].[Reports] r WITH (UPDLOCK, HOLDLOCK) ON r.[ReportId] = s.[ReportId]
LEFT JOIN [cc_report].[Approvals] c WITH (UPDLOCK, HOLDLOCK)
    ON c.[ReportId] = s.[ReportId] AND c.[Kind] = N'credit'
WHERE r.[PublicId] = :publicId AND s.[Kind] = N'settlement'
SQL,
                [':publicId' => $creditNote->publicId],
            );
            $row = $locked[0] ?? null;
            if (!is_array($row)) {
                throw CustomerContactReportFileFailure::creditNoteNotAllowed(
                    'A credit note can be attached only after the credit is approved and Accounts has the step.',
                );
            }
            $creditStatus = AzureSqlValueMapper::nullableString($row['CreditStatus'] ?? null);
            if ($creditStatus !== 'approved') {
                throw CustomerContactReportFileFailure::creditNoteNotAllowed(
                    'A credit note can be attached only after the credit is approved and Accounts has the step.',
                );
            }
            $currentVersion = ProductRowVersionMapper::toToken($row['RowVersion'] ?? null);
            $expected = CustomerContactReportRowVersion::fromBase64($creditNote->expectedSettlementRowVersion);
            if (!hash_equals($currentVersion->toBinary(), $expected->toBinary())) {
                throw CustomerContactReportApprovalFailure::conflict();
            }
            if (!CustomerContactReportAssigneeMatch::isCurrent(
                $creditNote->actorUserId,
                $creditNote->actorEmail,
                AzureSqlValueMapper::nullableInt($row['ApproverUserId'] ?? null),
                AzureSqlValueMapper::nullableString($row['Approver'] ?? null),
                AzureSqlValueMapper::nullableString($row['ApproverEmail'] ?? null),
            )) {
                throw CustomerContactReportFileFailure::settlementNotAssignee();
            }
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if ($status !== 'pending') {
                throw CustomerContactReportFileFailure::settlementNotPending();
            }

            $stamp = AzureSqlValueMapper::formatDateTime($creditNote->decidedAtUtc);
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [cc_report].[Attachments] (
    [ReportId], [FileUrl], [FileName], [FileSize], [ContentType], [Kind],
    [CreatedByUserId], [CreatedByEmail], [CreatedAtUtc]
) VALUES (
    :reportId, :fileUrl, :fileName, :fileSize, :contentType, N'credit_note',
    :createdByUserId, :createdByEmail, :createdAtUtc
)
SQL,
                [
                    ':reportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                    ':fileUrl' => $creditNote->fileUrl,
                    ':fileName' => $creditNote->fileName,
                    ':fileSize' => $creditNote->fileSize,
                    ':contentType' => $creditNote->contentType,
                    ':createdByUserId' => $creditNote->actorUserId,
                    ':createdByEmail' => $creditNote->actorEmail,
                    ':createdAtUtc' => $stamp,
                ],
            );
            $this->connection->execute(
                <<<'SQL'
UPDATE [cc_report].[Approvals]
SET [Status] = N'approved', [Note] = :note, [DecidedAtUtc] = :decidedAtUtc, [UpdatedAtUtc] = :updatedAtUtc
WHERE [ApprovalId] = :approvalId AND [Status] = N'pending'
SQL,
                [
                    ':note' => $creditNote->note,
                    ':decidedAtUtc' => $stamp,
                    ':updatedAtUtc' => $stamp,
                    ':approvalId' => AzureSqlValueMapper::intValue($row['ApprovalId'] ?? 0),
                ],
            );
            // Finance approval closes the report, from assigned or completed.
            $closedBy = AzureSqlValueMapper::nullableString($row['ApproverName'] ?? null)
                ?? AzureSqlValueMapper::stringValue($row['Approver'] ?? '');
            $this->connection->execute(
                <<<'SQL'
UPDATE [cc_report].[Reports]
SET [Status] = N'closed', [ClosedBy] = :closedBy, [ClosedByUserId] = :closedByUserId,
    [ClosedDate] = :closedDate, [ClosedAtUtc] = :closedAtUtc, [UpdatedAtUtc] = :updatedAtUtc
WHERE [ReportId] = :reportId AND [Status] IN (N'assigned', N'completed')
SQL,
                [
                    ':closedBy' => $closedBy,
                    ':closedByUserId' => $creditNote->actorUserId,
                    ':closedDate' => $creditNote->decidedAtUtc->format('Y-m-d'),
                    ':closedAtUtc' => $stamp,
                    ':updatedAtUtc' => $stamp,
                    ':reportId' => AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                ],
            );

            return new CustomerContactReportStoredAttachment(
                reportId: AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
                publicId: $creditNote->publicId,
                fileUrl: $creditNote->fileUrl,
                fileName: $creditNote->fileName,
                fileSize: $creditNote->fileSize,
                contentType: $creditNote->contentType,
                kind: 'credit_note',
                createdByUserId: $creditNote->actorUserId,
                createdByEmail: $creditNote->actorEmail,
                createdAtUtc: $creditNote->decidedAtUtc,
            );
        });
    }

    public function saveSignature(CustomerContactReportSignature $signature): CustomerContactReportDetails
    {
        return $this->runInTransaction($this->connection, function () use ($signature): CustomerContactReportDetails {
            $expected = CustomerContactReportRowVersion::fromBase64($signature->expectedRowVersion);
            $updated = $this->connection->fetchAll(
                <<<'SQL'
UPDATE [cc_report].[Reports]
SET
    [IcarOperatorName] = :operatorName,
    [IcarSignedAt] = :signedAt,
    [IcarSignatureUrl] = :signatureUrl,
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion
  AND [Status] = N'assigned'
  AND [Route] = N'icar'
  AND ([IcarSignatureUrl] IS NULL OR [IcarSignatureUrl] = N'')
  AND (
      [AssignedToUserId] = :actorUserId
      OR LOWER([AssignedTo]) = LOWER(:actorLogin)
      OR LOWER([AssignedToEmail]) = LOWER(:actorEmail)
  )
SQL,
                [
                    ':operatorName' => $signature->operatorName,
                    ':signedAt' => $signature->signedAt,
                    ':signatureUrl' => $signature->signatureUrl,
                    ':updatedAtUtc' => AzureSqlValueMapper::formatDateTime($signature->signedAtUtc),
                    ':publicId' => $signature->publicId,
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                        RowVersionToken::fromBinary($expected->toBinary()),
                    ),
                    ':actorUserId' => $signature->actorUserId,
                    ':actorLogin' => $signature->actorEmail,
                    ':actorEmail' => $signature->actorEmail,
                ],
            );
            if ($updated === []) {
                $this->explainSignatureMiss($signature);
            }

            return $this->reload($signature->publicId);
        });
    }

    public function updateReport(CustomerContactReportUpdate $update): CustomerContactReportUpdateOutcome
    {
        return $this->runInTransaction($this->connection, function () use ($update): CustomerContactReportUpdateOutcome {
            $row = $this->lockReport($update->publicId, $update->expectedRowVersion);
            $reportId = AzureSqlValueMapper::intValue($row['ReportId'] ?? 0);
            $stamp = AzureSqlValueMapper::formatDateTime($update->updatedAtUtc);

            $changes = $update->changes;
            unset($changes['lines']);
            $reset = false;
            if ($update->creditChanged) {
                $state = $this->connection->fetchAll(
                    <<<'SQL'
SELECT
    (SELECT [Status] FROM [cc_report].[Approvals] WITH (UPDLOCK, HOLDLOCK) WHERE [ReportId] = :reportId AND [Kind] = N'credit') AS [CreditStatus],
    (SELECT COUNT(1) FROM [cc_report].[Approvals] WITH (UPDLOCK, HOLDLOCK) WHERE [ReportId] = :reportId2 AND [Kind] = N'settlement') AS [SettlementRows]
SQL,
                    [':reportId' => $reportId, ':reportId2' => $reportId],
                )[0] ?? [];
                $creditStatus = AzureSqlValueMapper::nullableString($state['CreditStatus'] ?? null);
                $settlementRows = AzureSqlValueMapper::intValue($state['SettlementRows'] ?? 0);
                if (($creditStatus !== null && $creditStatus !== 'pending') || $settlementRows > 0) {
                    $reset = true;
                    $changes['creditReviewedBy'] = null;
                    $changes['creditReviewedDate'] = null;
                }
            }

            $sets = ['[UpdatedAtUtc] = :updatedAtUtc'];
            $params = [':updatedAtUtc' => $stamp];
            $n = 0;
            foreach ($changes as $property => $value) {
                $map = self::UPDATE_COLUMNS[$property] ?? null;
                if ($map === null) {
                    throw new \InvalidArgumentException('Unsupported Customer Contact column: ' . $property);
                }
                [$column, $kind] = $map;
                $name = ':v' . $n++;
                $sets[] = '[' . $column . '] = ' . $name;
                if ($value instanceof DateTimeImmutable) {
                    $params[$name] = $value->format('Y-m-d');
                } elseif ($kind === 'blankNull' && $value === '') {
                    $params[$name] = null;
                } else {
                    $params[$name] = is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
                }
            }
            $this->updateWorkflow($update->publicId, $update->expectedRowVersion, $params, 'UPDATE [cc_report].[Reports]
SET ' . implode(', ', $sets) . '
OUTPUT CONVERT(NVARCHAR(36), inserted.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [RowVersion] = :expectedRowVersion');

            if ($update->lines !== null) {
                $this->connection->execute('DELETE FROM [cc_report].[ReportLines] WHERE [ReportId] = :reportId', [':reportId' => $reportId]);
                $this->insertLines($reportId, $update->lines);
            }

            $creditApproval = null;
            if ($reset) {
                $this->connection->execute(
                    <<<'SQL'
UPDATE [cc_report].[Approvals]
SET [Status] = N'pending', [Note] = NULL, [DecidedAtUtc] = NULL, [RequestedAtUtc] = :now, [UpdatedAtUtc] = :now2
WHERE [ReportId] = :reportId AND [Kind] = N'credit' AND [Status] <> N'pending'
SQL,
                    [':now' => $stamp, ':now2' => $stamp, ':reportId' => $reportId],
                );
                $this->connection->execute(
                    'DELETE FROM [cc_report].[Approvals] WHERE [ReportId] = :reportId AND [Kind] = N\'settlement\'',
                    [':reportId' => $reportId],
                );
                $creditApproval = $this->approvalOrNull($update->publicId, 'credit');
            }

            return new CustomerContactReportUpdateOutcome($this->reload($update->publicId), $reset, $creditApproval);
        });
    }

    /**
     * @param list<CustomerContactReportLine> $lines
     */
    private function insertLines(int $reportId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [cc_report].[ReportLines] (
    [ReportId], [OrderNo], [InvoiceNo], [LineNumber], [ProductCode], [Description], [Qty], [Unit], [Packs],
    [PackSize], [UnitPrice], [Net], [CreditQty], [CreditRate], [CreditNet], [GstRate], [Warehouse], [Comment]
) VALUES (
    :reportId, :orderNo, :invoiceNo, :lineNumber, :productCode, :description, :qty, :unit, :packs,
    :packSize, :unitPrice, :net, :creditQty, :creditRate, :creditNet, :gstRate, :warehouse, :comment
)
SQL,
                [
                    ':reportId' => $reportId,
                    ':orderNo' => $line->orderNo,
                    ':invoiceNo' => $line->invoiceNo,
                    ':lineNumber' => $line->lineNo,
                    ':productCode' => $line->productCode,
                    ':description' => $line->description,
                    ':qty' => $line->qty,
                    ':unit' => $line->unit,
                    ':packs' => $line->packs,
                    ':packSize' => $line->packSize,
                    ':unitPrice' => $line->unitPrice,
                    ':net' => $line->net,
                    ':creditQty' => $line->creditQty,
                    ':creditRate' => $line->creditRate,
                    ':creditNet' => $line->creditNet,
                    ':gstRate' => $line->gstRate,
                    ':warehouse' => $line->warehouse,
                    ':comment' => $line->comment,
                ],
            );
        }
    }

    private function withLines(CustomerContactReportDetails $report): CustomerContactReportDetails
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    l.[OrderNo], l.[InvoiceNo], l.[LineNumber], l.[ProductCode], l.[Description],
    CONVERT(VARCHAR(32), l.[Qty]) AS [Qty], l.[Unit], l.[Packs],
    CONVERT(VARCHAR(32), l.[PackSize]) AS [PackSize],
    CONVERT(VARCHAR(32), l.[UnitPrice]) AS [UnitPrice],
    CONVERT(VARCHAR(32), l.[Net]) AS [Net],
    CONVERT(VARCHAR(32), l.[CreditQty]) AS [CreditQty],
    CONVERT(VARCHAR(32), l.[CreditRate]) AS [CreditRate],
    CONVERT(VARCHAR(32), l.[CreditNet]) AS [CreditNet],
    CONVERT(VARCHAR(32), l.[GstRate]) AS [GstRate],
    l.[Warehouse], l.[Comment]
FROM [cc_report].[ReportLines] l
WHERE l.[ReportId] = :reportId
ORDER BY l.[OrderNo], l.[LineNumber]
SQL,
            [':reportId' => $report->reportId],
        );
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = new CustomerContactReportLine(
                orderNo: AzureSqlValueMapper::stringValue($row['OrderNo'] ?? ''),
                invoiceNo: AzureSqlValueMapper::stringValue($row['InvoiceNo'] ?? ''),
                lineNo: AzureSqlValueMapper::intValue($row['LineNumber'] ?? 0),
                productCode: AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
                description: AzureSqlValueMapper::stringValue($row['Description'] ?? ''),
                qty: AzureSqlValueMapper::stringValue($row['Qty'] ?? '0'),
                unit: AzureSqlValueMapper::stringValue($row['Unit'] ?? ''),
                packs: AzureSqlValueMapper::intValue($row['Packs'] ?? 0),
                packSize: AzureSqlValueMapper::stringValue($row['PackSize'] ?? '0'),
                unitPrice: AzureSqlValueMapper::stringValue($row['UnitPrice'] ?? '0'),
                net: AzureSqlValueMapper::stringValue($row['Net'] ?? '0'),
                creditQty: AzureSqlValueMapper::stringValue($row['CreditQty'] ?? '0'),
                creditRate: AzureSqlValueMapper::stringValue($row['CreditRate'] ?? '0'),
                creditNet: AzureSqlValueMapper::stringValue($row['CreditNet'] ?? '0'),
                warehouse: AzureSqlValueMapper::stringValue($row['Warehouse'] ?? ''),
                comment: AzureSqlValueMapper::stringValue($row['Comment'] ?? ''),
                gstRate: AzureSqlValueMapper::nullableString($row['GstRate'] ?? null),
            );
        }

        return $lines === [] ? $report : $report->withChanges($report->rowVersion, $report->updatedAtUtc, ['lines' => $lines]);
    }

    private function approvalOrNull(string $publicId, string $kind): ?CustomerContactReportApproval
    {
        foreach ($this->approvalsFor($publicId) as $approval) {
            if ($approval->kind === $kind) {
                return $approval;
            }
        }

        return null;
    }

    public function numberSequenceRows(): int
    {
        $rows = $this->connection->fetchAll('SELECT COUNT(1) AS [Total] FROM [cc_report].[NumberSequences]');

        return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    public function lastAllocatedSequence(int $year): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [LastSequence] AS [Total] FROM [cc_report].[NumberSequences] WHERE [SequenceYear] = :sequenceYear',
            [':sequenceYear' => $year],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    /**
     * Lock the year row, including the gap where it does not exist yet.
     * HOLDLOCK is serializable for this statement, so two transactions cannot
     * both insert sequence 1 for a year that has no row.
     */
    private function allocateSequence(int $year, DateTimeImmutable $updatedAtUtc): int
    {
        $locked = $this->connection->fetchAll(
            <<<'SQL'
SELECT [LastSequence]
FROM [cc_report].[NumberSequences] WITH (UPDLOCK, HOLDLOCK)
WHERE [SequenceYear] = :sequenceYear
SQL,
            [':sequenceYear' => $year],
        );
        $stamp = AzureSqlValueMapper::formatDateTime($updatedAtUtc);
        if ($locked === []) {
            $inserted = $this->connection->fetchAll(
                <<<'SQL'
INSERT INTO [cc_report].[NumberSequences] ([SequenceYear], [LastSequence], [UpdatedAtUtc])
OUTPUT inserted.[LastSequence] AS [LastSequence]
VALUES (:sequenceYear, 1, :updatedAtUtc)
SQL,
                [':sequenceYear' => $year, ':updatedAtUtc' => $stamp],
            );

            return AzureSqlValueMapper::intValue($inserted[0]['LastSequence'] ?? 1);
        }

        $updated = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [cc_report].[NumberSequences]
SET [LastSequence] = [LastSequence] + 1,
    [UpdatedAtUtc] = :updatedAtUtc
OUTPUT inserted.[LastSequence] AS [LastSequence]
WHERE [SequenceYear] = :sequenceYear
SQL,
            [':sequenceYear' => $year, ':updatedAtUtc' => $stamp],
        );

        return AzureSqlValueMapper::intValue($updated[0]['LastSequence'] ?? 0);
    }

    /**
     * Creator, current assignee, or an approver recorded on the report.
     * Each value is bound once: PDO_SQLSRV cannot reuse a named parameter.
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function visibility(CustomerContactReportVisibility $visibility): array
    {
        return [
            '(
                :readAll = 1
                OR r.[CreatedByUserId] = :creatorUserId
                OR LOWER(r.[CreatedByEmail]) = LOWER(:creatorEmail)
                OR r.[AssignedToUserId] = :assigneeUserId
                OR LOWER(r.[AssignedTo]) = LOWER(:assigneeLogin)
                OR LOWER(r.[AssignedToEmail]) = LOWER(:assigneeEmail)
                OR EXISTS (
                    SELECT 1
                    FROM [cc_report].[Approvals] a
                    WHERE a.[ReportId] = r.[ReportId]
                      AND (
                          a.[ApproverUserId] = :approvalUserId
                          OR LOWER(a.[Approver]) = LOWER(:approvalLogin)
                          OR LOWER(a.[ApproverEmail]) = LOWER(:approvalEmail)
                      )
                )
            )',
            [
                ':readAll' => $visibility->readAll ? 1 : 0,
                ':creatorUserId' => $visibility->userId,
                ':creatorEmail' => $visibility->email,
                ':assigneeUserId' => $visibility->userId,
                ':assigneeLogin' => $visibility->email,
                ':assigneeEmail' => $visibility->email,
                ':approvalUserId' => $visibility->userId,
                ':approvalLogin' => $visibility->email,
                ':approvalEmail' => $visibility->email,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: array<string, int|string|null>}
     */
    private function filters(CustomerContactReportListCriteria $criteria, CustomerContactReportVisibility $visibility): array
    {
        [$visibilitySql, $params] = $this->visibility($visibility);
        $clauses = [$visibilitySql];
        if ($criteria->status !== null) {
            $clauses[] = 'r.[Status] = :status';
            $params[':status'] = $criteria->status;
        }
        if ($criteria->month !== null) {
            $clauses[] = 'MONTH(r.[ReportDate]) = :month';
            $params[':month'] = $criteria->month;
        }
        if ($criteria->year !== null) {
            $clauses[] = 'YEAR(r.[ReportDate]) = :year';
            $params[':year'] = $criteria->year;
        }
        if ($criteria->customer !== null) {
            $clauses[] = 'UPPER(r.[CustomerCode]) = UPPER(:customer)';
            $params[':customer'] = $criteria->customer;
        }
        if ($criteria->assignee !== null) {
            $clauses[] = 'UPPER(r.[AssignedTo]) = UPPER(:assignee)';
            $params[':assignee'] = $criteria->assignee;
        }
        if ($criteria->location !== null) {
            $clauses[] = 'r.[Location] = :location';
            $params[':location'] = $criteria->location;
        }
        if ($criteria->errorCode !== null) {
            $clauses[] = 'r.[ErrorCode] = :errorCode';
            $params[':errorCode'] = $criteria->errorCode;
        }
        if ($criteria->search !== null) {
            $pattern = CustomerContactReportSearch::likePattern($criteria->search);
            $columns = ['CcNumber', 'CustomerCode', 'CustomerContact', 'ProductCode', 'Description'];
            $likes = [];
            foreach ($columns as $index => $column) {
                $name = ':search' . $index;
                $likes[] = 'r.[' . $column . '] LIKE ' . $name . " ESCAPE '!'";
                $params[$name] = $pattern;
            }
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function counts(array $rows): CustomerContactReportCounts
    {
        $counts = ['submitted' => 0, 'assigned' => 0, 'completed' => 0, 'closed' => 0];
        foreach ($rows as $row) {
            $status = AzureSqlValueMapper::stringValue($row['Status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = AzureSqlValueMapper::intValue($row['Total'] ?? 0);
            }
        }

        return new CustomerContactReportCounts($counts['submitted'], $counts['assigned'], $counts['completed'], $counts['closed']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CustomerContactReportDetails
    {
        return new CustomerContactReportDetails(
            reportId: AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
            publicId: strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            reportDate: self::date(AzureSqlValueMapper::stringValue($row['ReportDate'] ?? ''), 'ReportDate'),
            location: AzureSqlValueMapper::stringValue($row['Location'] ?? ''),
            customerCode: AzureSqlValueMapper::stringValue($row['CustomerCode'] ?? ''),
            customerContact: AzureSqlValueMapper::nullableString($row['CustomerContact'] ?? null),
            tricabRefTrace: AzureSqlValueMapper::nullableString($row['TricabRefTrace'] ?? null),
            traceNum: AzureSqlValueMapper::nullableString($row['TraceNum'] ?? null),
            conNoteNo: AzureSqlValueMapper::nullableString($row['ConNoteNo'] ?? null),
            tricabContact: AzureSqlValueMapper::nullableString($row['TricabContact'] ?? null),
            productCode: AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
            description: AzureSqlValueMapper::stringValue($row['Description'] ?? ''),
            status: AzureSqlValueMapper::stringValue($row['Status'] ?? ''),
            route: AzureSqlValueMapper::nullableString($row['Route'] ?? null),
            ccNumber: AzureSqlValueMapper::nullableString($row['CcNumber'] ?? null),
            errorCode: AzureSqlValueMapper::nullableString($row['ErrorCode'] ?? null),
            assignedTo: AzureSqlValueMapper::nullableString($row['AssignedTo'] ?? null),
            assignedToUserId: AzureSqlValueMapper::nullableInt($row['AssignedToUserId'] ?? null),
            assignedToName: AzureSqlValueMapper::nullableString($row['AssignedToName'] ?? null),
            assignedToEmail: AzureSqlValueMapper::nullableString($row['AssignedToEmail'] ?? null),
            assignedBy: AzureSqlValueMapper::nullableString($row['AssignedBy'] ?? null),
            assignedAtUtc: AzureSqlValueMapper::nullableDateTime($row['AssignedAtUtc'] ?? null),
            assigneeActionTaken: AzureSqlValueMapper::nullableString($row['AssigneeActionTaken'] ?? null),
            assigneeFindings: AzureSqlValueMapper::nullableString($row['AssigneeFindings'] ?? null),
            assigneeCompletedAtUtc: AzureSqlValueMapper::nullableDateTime($row['AssigneeCompletedAtUtc'] ?? null),
            closedBy: AzureSqlValueMapper::nullableString($row['ClosedBy'] ?? null),
            closedByUserId: AzureSqlValueMapper::nullableInt($row['ClosedByUserId'] ?? null),
            closedDate: self::nullableDate(AzureSqlValueMapper::nullableString($row['ClosedDate'] ?? null)),
            closedAtUtc: AzureSqlValueMapper::nullableDateTime($row['ClosedAtUtc'] ?? null),
            createdByUserId: AzureSqlValueMapper::intValue($row['CreatedByUserId'] ?? 0),
            createdByEmail: AzureSqlValueMapper::stringValue($row['CreatedByEmail'] ?? ''),
            createdByName: AzureSqlValueMapper::stringValue($row['CreatedByName'] ?? ''),
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            updatedAtUtc: AzureSqlValueMapper::requireDateTime($row['UpdatedAtUtc'] ?? null, 'UpdatedAtUtc'),
            investigationDueDate: AzureSqlValueMapper::nullableString($row['InvestigationDueDate'] ?? null),
            ccAssessedBy: AzureSqlValueMapper::nullableString($row['CcAssessedBy'] ?? null),
            creditRequired: AzureSqlValueMapper::nullableString($row['CreditRequired'] ?? null),
            restockingFeeApplies: AzureSqlValueMapper::nullableString($row['RestockingFeeApplies'] ?? null),
            actionsTaken: AzureSqlValueMapper::nullableString($row['ActionsTaken'] ?? null),
            schedulingInformed: AzureSqlValueMapper::nullableString($row['SchedulingInformed'] ?? null),
            returnArrangedBy: AzureSqlValueMapper::nullableString($row['ReturnArrangedBy'] ?? null),
            expectedReturnDate: AzureSqlValueMapper::nullableString($row['ExpectedReturnDate'] ?? null),
            goodsReturned: AzureSqlValueMapper::nullableString($row['GoodsReturned'] ?? null),
            goodsReturnedDate: AzureSqlValueMapper::nullableString($row['GoodsReturnedDate'] ?? null),
            carrierBooked: AzureSqlValueMapper::nullableString($row['CarrierBooked'] ?? null),
            carrierBookedDate: AzureSqlValueMapper::nullableString($row['CarrierBookedDate'] ?? null),
            creditRaised: AzureSqlValueMapper::nullableString($row['CreditRaised'] ?? null),
            creditRaisedDate: AzureSqlValueMapper::nullableString($row['CreditRaisedDate'] ?? null),
            goodsReplaced: AzureSqlValueMapper::nullableString($row['GoodsReplaced'] ?? null),
            goodsReplacedDate: AzureSqlValueMapper::nullableString($row['GoodsReplacedDate'] ?? null),
            carRaised: AzureSqlValueMapper::nullableString($row['CarRaised'] ?? null),
            carRaisedDate: AzureSqlValueMapper::nullableString($row['CarRaisedDate'] ?? null),
            valueExGst: AzureSqlValueMapper::nullableString($row['ValueExGst'] ?? null),
            restockingAmount: AzureSqlValueMapper::nullableString($row['RestockingAmount'] ?? null),
            subTotal: AzureSqlValueMapper::nullableString($row['SubTotal'] ?? null),
            gst: AzureSqlValueMapper::nullableString($row['Gst'] ?? null),
            creditTotal: AzureSqlValueMapper::nullableString($row['CreditTotal'] ?? null),
            rootCause: AzureSqlValueMapper::nullableString($row['RootCause'] ?? null),
            correctiveAction: AzureSqlValueMapper::nullableString($row['CorrectiveAction'] ?? null),
            notes: AzureSqlValueMapper::nullableString($row['Notes'] ?? null),
            creditReviewedBy: AzureSqlValueMapper::nullableString($row['CreditReviewedBy'] ?? null),
            creditReviewedDate: AzureSqlValueMapper::nullableString($row['CreditReviewedDate'] ?? null),
            icarOperatorName: AzureSqlValueMapper::nullableString($row['IcarOperatorName'] ?? null),
            icarSignedAt: AzureSqlValueMapper::nullableString($row['IcarSignedAt'] ?? null),
            icarSignatureUrl: AzureSqlValueMapper::nullableString($row['IcarSignatureUrl'] ?? null),
            orderNo: AzureSqlValueMapper::nullableString($row['OrderNo'] ?? null),
            invoiceNo: AzureSqlValueMapper::nullableString($row['InvoiceNo'] ?? null),
            creditType: AzureSqlValueMapper::nullableString($row['CreditType'] ?? null),
            quotedAmount: AzureSqlValueMapper::nullableString($row['QuotedAmount'] ?? null),
            invoicedAmount: AzureSqlValueMapper::nullableString($row['InvoicedAmount'] ?? null),
            addGst: AzureSqlValueMapper::nullableString($row['AddGst'] ?? null),
            requestedActions: AzureSqlValueMapper::nullableString($row['RequestedActions'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lockReport(string $publicId, string $expectedRowVersion): array
    {
        $locked = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    r.[ReportId],
    r.[Status],
    r.[RowVersion],
    r.[AssignedTo],
    r.[AssignedToUserId],
    r.[AssignedToEmail],
    r.[AssigneeActionTaken],
    r.[AssigneeFindings],
    r.[IcarSignatureUrl],
    CONVERT(VARCHAR(10), r.[IcarSignedAt], 23) AS [IcarSignedAt]
FROM [cc_report].[Reports] r WITH (UPDLOCK, HOLDLOCK)
WHERE r.[PublicId] = :publicId
SQL,
            [':publicId' => $publicId],
        );
        $row = $locked[0] ?? null;
        if (!is_array($row)) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        $currentVersion = ProductRowVersionMapper::toToken($row['RowVersion'] ?? null);
        $expected = CustomerContactReportRowVersion::fromBase64($expectedRowVersion);
        if (!hash_equals($currentVersion->toBinary(), $expected->toBinary())) {
            throw CustomerContactReportConflict::stale();
        }

        return $row;
    }

    /**
     * @param array<string, int|string|SqlBinary|null> $params
     */
    private function updateWorkflow(string $publicId, string $expectedRowVersion, array $params, string $sql): void
    {
        $expected = CustomerContactReportRowVersion::fromBase64($expectedRowVersion);
        $params[':publicId'] = $publicId;
        $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary(
            RowVersionToken::fromBinary($expected->toBinary()),
        );
        $updated = $this->connection->fetchAll($sql, $params);
        if ($updated === []) {
            throw CustomerContactReportConflict::stale();
        }
    }

    private function reload(string $publicId): CustomerContactReportDetails
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . '
FROM [cc_report].[Reports] r
WHERE r.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        return $this->withLines($this->hydrate($row));
    }

    private function approvalByKind(string $publicId, string $kind): CustomerContactReportApproval
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::APPROVAL_COLUMNS . '
FROM [cc_report].[Approvals] a
JOIN [cc_report].[Reports] r ON r.[ReportId] = a.[ReportId]
WHERE r.[PublicId] = :publicId AND a.[Kind] = :kind',
            [':publicId' => $publicId, ':kind' => $kind],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw CustomerContactReportApprovalFailure::notFound($kind);
        }

        return $this->hydrateApproval($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateApproval(array $row): CustomerContactReportApproval
    {
        return new CustomerContactReportApproval(
            approvalId: AzureSqlValueMapper::intValue($row['ApprovalId'] ?? 0),
            reportId: AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
            publicId: strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            kind: AzureSqlValueMapper::stringValue($row['Kind'] ?? ''),
            status: AzureSqlValueMapper::stringValue($row['Status'] ?? ''),
            approver: AzureSqlValueMapper::stringValue($row['Approver'] ?? ''),
            approverUserId: AzureSqlValueMapper::nullableInt($row['ApproverUserId'] ?? null),
            approverName: AzureSqlValueMapper::stringValue($row['ApproverName'] ?? ''),
            approverEmail: AzureSqlValueMapper::stringValue($row['ApproverEmail'] ?? ''),
            requestedBy: AzureSqlValueMapper::stringValue($row['RequestedBy'] ?? ''),
            requestedByUserId: AzureSqlValueMapper::nullableInt($row['RequestedByUserId'] ?? null),
            requestedAtUtc: AzureSqlValueMapper::requireDateTime($row['RequestedAtUtc'] ?? null, 'RequestedAtUtc'),
            note: AzureSqlValueMapper::nullableString($row['Note'] ?? null),
            decidedAtUtc: AzureSqlValueMapper::nullableDateTime($row['DecidedAtUtc'] ?? null),
        );
    }

    /**
     * @return list<array{kind: string, status: string}>
     */
    private static function parseApprovalSummary(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $items = [];
        foreach (explode(',', $value) as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $items[] = ['kind' => $parts[0], 'status' => $parts[1]];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAttachment(array $row): CustomerContactReportStoredAttachment
    {
        return new CustomerContactReportStoredAttachment(
            reportId: AzureSqlValueMapper::intValue($row['ReportId'] ?? 0),
            publicId: strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            fileUrl: AzureSqlValueMapper::stringValue($row['FileUrl'] ?? ''),
            fileName: AzureSqlValueMapper::stringValue($row['FileName'] ?? ''),
            fileSize: AzureSqlValueMapper::intValue($row['FileSize'] ?? 0),
            contentType: AzureSqlValueMapper::stringValue($row['ContentType'] ?? ''),
            kind: AzureSqlValueMapper::stringValue($row['Kind'] ?? ''),
            createdByUserId: AzureSqlValueMapper::nullableInt($row['CreatedByUserId'] ?? null),
            createdByEmail: AzureSqlValueMapper::nullableString($row['CreatedByEmail'] ?? null),
            createdAtUtc: AzureSqlValueMapper::nullableDateTime($row['CreatedAtUtc'] ?? null),
        );
    }

    private function explainSignatureMiss(CustomerContactReportSignature $signature): void
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT r.[Status], r.[Route], r.[RowVersion], r.[IcarSignatureUrl],
       r.[AssignedTo], r.[AssignedToUserId], r.[AssignedToEmail]
FROM [cc_report].[Reports] r WITH (UPDLOCK, HOLDLOCK)
WHERE r.[PublicId] = :publicId
SQL,
            [':publicId' => $signature->publicId],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw CustomerContactReportNotFound::forPublicId($signature->publicId);
        }
        $currentVersion = ProductRowVersionMapper::toToken($row['RowVersion'] ?? null);
        $expected = CustomerContactReportRowVersion::fromBase64($signature->expectedRowVersion);
        if (!hash_equals($currentVersion->toBinary(), $expected->toBinary())) {
            throw CustomerContactReportConflict::stale();
        }
        $url = AzureSqlValueMapper::nullableString($row['IcarSignatureUrl'] ?? null);
        if ($url !== null && trim($url) !== '') {
            throw CustomerContactReportFileFailure::signatureExists();
        }
        throw CustomerContactReportFileFailure::signatureNotAllowed('A signature can be recorded only on an assigned ICAR Customer Contact.');
    }

    private static function date(string $value, string $field): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \RuntimeException('Missing or invalid date for ' . $field . '.');
        }

        return $parsed;
    }

    private static function nullableDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::date($value, 'date');
    }
}
