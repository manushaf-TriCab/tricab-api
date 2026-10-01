<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;

final class ListCustomerContactReportAttachmentsService
{
    public function __construct(
        private readonly CustomerContactReportRepository $reports,
    ) {
    }

    /**
     * Evidence follows report visibility. A credit note follows the settlement
     * remedy: the recorded Accounts person, or a register reader. A superseded
     * signature is never listed.
     *
     * @return list<CustomerContactReportStoredAttachment>
     */
    public function execute(string $publicId, CustomerContactReportVisibility $visibility): array
    {
        $report = $this->reports->findVisible(CustomerContactReportPublicId::fromString($publicId), $visibility);
        if ($report === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        $seesCreditNote = $visibility->readAll;
        if (!$seesCreditNote) {
            foreach ($this->reports->approvalsFor($report->publicId) as $approval) {
                if ($approval->kind !== 'settlement') {
                    continue;
                }
                $seesCreditNote = CustomerContactReportAssigneeMatch::isCurrent(
                    $visibility->userId,
                    $visibility->email,
                    $approval->approverUserId,
                    $approval->approver,
                    $approval->approverEmail,
                );
            }
        }

        $visible = [];
        foreach ($this->reports->attachmentsFor($report->publicId) as $attachment) {
            if ($attachment->kind === 'signature_superseded') {
                continue;
            }
            if ($attachment->kind === 'credit_note' && !$seesCreditNote) {
                continue;
            }
            if ($attachment->kind !== 'evidence' && $attachment->kind !== 'credit_note') {
                continue;
            }
            $visible[] = $attachment;
        }

        return $visible;
    }
}
