<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use Throwable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportEvidence;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredAttachment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportBlobPath;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportFilePolicy;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportStatus;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class AttachCustomerContactReportEvidenceService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'kind',
        'fileUrl',
        'fileName',
        'fileSize',
        'contentType',
        'createdBy',
        'createdByUserId',
        'createdByEmail',
        'createdAt',
        'createdAtUtc',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportBlobWrite $blobs,
        private readonly Clock $clock,
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

        $id = CustomerContactReportPublicId::fromString($publicId);
        $report = $this->reports->findVisible($id, $visibility);
        if ($report === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }
        $email = strtolower(trim($actor->emailAddress));
        $isCreator = ($actor->userId > 0 && $report->createdByUserId === $actor->userId)
            || ($email !== '' && strtolower($report->createdByEmail) === $email);
        if (!$isCreator) {
            throw CustomerContactReportFileFailure::evidenceNotAllowed('Only the person who raised this Customer Contact can add photos.');
        }
        if ($report->status !== CustomerContactReportStatus::SUBMITTED) {
            throw CustomerContactReportFileFailure::evidenceNotAllowed('Photos can only be added before the Customer Contact is assigned.');
        }

        $evidenceCount = 0;
        foreach ($this->reports->attachmentsFor($report->publicId) as $existing) {
            if ($existing->kind === 'evidence') {
                $evidenceCount++;
            }
        }
        if ($evidenceCount >= CustomerContactReportFilePolicy::MAX_EVIDENCE) {
            throw CustomerContactReportFileFailure::invalid('This Customer Contact already has 20 attachments.');
        }

        $inspected = CustomerContactReportFilePolicy::evidence()->inspect($originalName, $bytes, $clientMediaType);
        $extension = strtolower(pathinfo($inspected['fileName'], PATHINFO_EXTENSION));
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $stored = $this->blobs->put(
            CustomerContactReportBlobPath::evidence($now, $extension),
            $inspected['contentType'],
            $bytes,
        );

        try {
            return $this->reports->addEvidence(new CustomerContactReportEvidence(
                publicId: $report->publicId,
                fileUrl: $stored->url,
                fileName: $inspected['fileName'],
                fileSize: $inspected['size'],
                contentType: $inspected['contentType'],
                createdByUserId: $actor->userId,
                createdByEmail: $email,
                createdAtUtc: $now,
            ));
        } catch (Throwable $failure) {
            $this->blobs->rollback($stored);
            throw $failure;
        }
    }
}
