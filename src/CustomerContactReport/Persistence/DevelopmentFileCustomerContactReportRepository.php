<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Persistence;

use RuntimeException;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApproval;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalDecision;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAssignment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportClosure;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCompletion;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCreditNote;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportEvidence;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListResult;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportReopen;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSignature;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdate;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdateOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;

/**
 * Development browser harness. Each php -S request starts a new process heap,
 * so this reloads the in-memory repository from a temp file on every call.
 * It is bound only when CUSTOMER_CONTACT_E2E_MODE is on, which refuses Azure SQL.
 */
final class DevelopmentFileCustomerContactReportRepository implements CustomerContactReportRepository
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function insert(NewCustomerContactReport $report): CustomerContactReportDetails
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportDetails => $inner->insert($report));
    }

    public function findVisible(
        CustomerContactReportPublicId $publicId,
        CustomerContactReportVisibility $visibility,
    ): ?CustomerContactReportDetails {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): ?CustomerContactReportDetails => $inner->findVisible($publicId, $visibility));
    }

    public function list(
        CustomerContactReportListCriteria $criteria,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportListResult {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportListResult => $inner->list($criteria, $visibility));
    }

    public function assign(CustomerContactReportAssignment $assignment): CustomerContactReportDetails
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportDetails => $inner->assign($assignment));
    }

    public function complete(CustomerContactReportCompletion $completion): CustomerContactReportDetails
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportDetails => $inner->complete($completion));
    }

    public function close(CustomerContactReportClosure $closure): CustomerContactReportDetails
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportDetails => $inner->close($closure));
    }

    public function updateReport(CustomerContactReportUpdate $update): CustomerContactReportUpdateOutcome
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportUpdateOutcome => $inner->updateReport($update));
    }

    public function reopen(CustomerContactReportReopen $reopen): CustomerContactReportDetails
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportDetails => $inner->reopen($reopen));
    }

    public function signatureSupersessions(string $publicId): array
    {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): array => $inner->signatureSupersessions($publicId));
    }

    public function numberSequenceRows(): int
    {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): int => $inner->numberSequenceRows());
    }

    public function lastAllocatedSequence(int $year): int
    {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): int => $inner->lastAllocatedSequence($year));
    }

    public function requestApproval(CustomerContactReportApprovalRequest $request): CustomerContactReportApprovalOutcome
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportApprovalOutcome => $inner->requestApproval($request));
    }

    public function decideApproval(CustomerContactReportApprovalDecision $decision): CustomerContactReportApprovalOutcome
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportApprovalOutcome => $inner->decideApproval($decision));
    }

    public function approvalsFor(string $publicId): array
    {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): array => $inner->approvalsFor($publicId));
    }

    public function approvalSummaries(array $publicIds): array
    {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): array => $inner->approvalSummaries($publicIds));
    }

    public function attachmentsFor(string $publicId): array
    {
        return $this->read(static fn (InMemoryCustomerContactReportRepository $inner): array => $inner->attachmentsFor($publicId));
    }

    public function addEvidence(CustomerContactReportEvidence $evidence): CustomerContactReportStoredAttachment
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportStoredAttachment => $inner->addEvidence($evidence));
    }

    public function completeSettlement(CustomerContactReportCreditNote $creditNote): CustomerContactReportStoredAttachment
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportStoredAttachment => $inner->completeSettlement($creditNote));
    }

    public function saveSignature(CustomerContactReportSignature $signature): CustomerContactReportDetails
    {
        return $this->write(static fn (InMemoryCustomerContactReportRepository $inner): CustomerContactReportDetails => $inner->saveSignature($signature));
    }

    /**
     * @template T
     * @param callable(InMemoryCustomerContactReportRepository): T $call
     * @return T
     */
    private function read(callable $call): mixed
    {
        return $this->withSnapshot($call, false);
    }

    /**
     * @template T
     * @param callable(InMemoryCustomerContactReportRepository): T $call
     * @return T
     */
    private function write(callable $call): mixed
    {
        return $this->withSnapshot($call, true);
    }

    /**
     * @template T
     * @param callable(InMemoryCustomerContactReportRepository): T $call
     * @return T
     */
    private function withSnapshot(callable $call, bool $persist): mixed
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Customer Contact E2E directory could not be created.');
        }

        $lockPath = $this->path . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Customer Contact E2E store could not be locked.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Customer Contact E2E store could not be locked.');
            }

            $inner = $this->load();
            $result = $call($inner);
            if ($persist) {
                $this->save($inner);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(): InMemoryCustomerContactReportRepository
    {
        if (!is_file($this->path)) {
            return new InMemoryCustomerContactReportRepository();
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Customer Contact E2E store could not be read.');
        }

        $loaded = unserialize($raw, ['allowed_classes' => true]);
        if (!$loaded instanceof InMemoryCustomerContactReportRepository) {
            throw new RuntimeException('Customer Contact E2E store is not a Customer Contact repository.');
        }

        return $loaded;
    }

    private function save(InMemoryCustomerContactReportRepository $inner): void
    {
        $temporary = $this->path . '.tmp';
        $payload = serialize($inner);
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Customer Contact E2E store could not be written.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $this->path)) {
            throw new RuntimeException('Customer Contact E2E store could not be replaced.');
        }
    }
}
