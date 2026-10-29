<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Contract;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCreditNote;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportEvidence;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSignature;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdate;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdateOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalDecision;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAssignment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportClosure;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCompletion;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportReopen;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListResult;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;

interface CustomerContactReportRepository
{
    public function insert(NewCustomerContactReport $report): CustomerContactReportDetails;

    public function findVisible(
        CustomerContactReportPublicId $publicId,
        CustomerContactReportVisibility $visibility,
    ): ?CustomerContactReportDetails;

    public function list(
        CustomerContactReportListCriteria $criteria,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportListResult;

    /**
     * Assign a submitted report, or reassign an assigned one.
     * The first assignment allocates the yearly sequence in the same transaction.
     */
    public function assign(CustomerContactReportAssignment $assignment): CustomerContactReportDetails;

    /**
     * assigned -> completed. Does not allocate a CC number.
     */
    public function complete(CustomerContactReportCompletion $completion): CustomerContactReportDetails;

    /**
     * completed -> closed. Does not allocate a CC number.
     */
    public function close(CustomerContactReportClosure $closure): CustomerContactReportDetails;

    /**
     * completed|closed -> assigned. Preserves the CC number. A stored ICAR
     * signature is copied to a signature_superseded attachment in the same
     * transaction before the active signature columns are cleared.
     */
    public function reopen(CustomerContactReportReopen $reopen): CustomerContactReportDetails;

    /**
     * @return list<CustomerContactReportStoredAttachment>
     */
    public function signatureSupersessions(string $publicId): array;

    /**
     * Rows in [cc_report].[NumberSequences]. Create must leave this at 0.
     */
    public function numberSequenceRows(): int;

    /**
     * Committed LastSequence for a calendar year. 0 when that year has no row.
     */
    public function lastAllocatedSequence(int $year): int;

    public function requestApproval(CustomerContactReportApprovalRequest $request): CustomerContactReportApprovalOutcome;

    public function decideApproval(CustomerContactReportApprovalDecision $decision): CustomerContactReportApprovalOutcome;

    /**
     * @return list<CustomerContactReportApproval>
     */
    public function approvalsFor(string $publicId): array;

    /**
     * Lightweight kind/status pairs for one list page. Not a per-row query.
     *
     * @param list<string> $publicIds
     * @return array<string, list<array{kind: string, status: string}>>
     */
    public function approvalSummaries(array $publicIds): array;

    /**
     * @return list<CustomerContactReportStoredAttachment>
     */
    public function attachmentsFor(string $publicId): array;

    public function addEvidence(CustomerContactReportEvidence $evidence): CustomerContactReportStoredAttachment;

    public function completeSettlement(CustomerContactReportCreditNote $creditNote): CustomerContactReportStoredAttachment;

    public function saveSignature(CustomerContactReportSignature $signature): CustomerContactReportDetails;

    /**
     * Apply an update guarded by the report row version (CustomerContactReportConflict
     * when stale). Lines are replaced in the same transaction, as live does with
     * DELETE then INSERT. When $update->creditChanged and a credit has been decided,
     * the credit goes back to pending, the settlement row is deleted and the credit
     * review stamp is cleared. A credit that is still pending is left alone.
     */
    public function updateReport(CustomerContactReportUpdate $update): CustomerContactReportUpdateOutcome;
}
