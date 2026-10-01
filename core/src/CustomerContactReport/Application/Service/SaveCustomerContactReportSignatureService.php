<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use Throwable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSignature;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportConflict;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportBlobPath;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSignatureImage;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRoute;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportStatus;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class SaveCustomerContactReportSignatureService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'status',
        'icarSignedAt',
        'icarSignatureUrl',
        'fileUrl',
        'kind',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportBlobWrite $blobs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        AuthenticatedUser $actor,
        string $publicId,
        array $payload,
        CustomerContactReportVisibility $visibility,
    ): CustomerContactReportDetails
    {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        $rowVersion = $payload['rowVersion'] ?? null;
        if (!is_string($rowVersion) || trim($rowVersion) === '') {
            throw InvalidCustomerContactReportValue::for('rowVersion', 'is required.');
        }
        $rowVersion = trim($rowVersion);
        CustomerContactReportRowVersion::fromBase64($rowVersion);

        $operator = $payload['icarOperatorName'] ?? null;
        if (!is_string($operator) || trim($operator) === '') {
            throw InvalidCustomerContactReportValue::for('icarOperatorName', 'is required.');
        }
        $operator = trim($operator);
        if (strlen($operator) > 255) {
            throw InvalidCustomerContactReportValue::for('icarOperatorName', 'is too long.');
        }

        $signature = $payload['signature'] ?? null;
        if (!is_string($signature)) {
            throw CustomerContactReportFileFailure::signatureInvalid('The signature must be a PNG data URL.');
        }
        $png = CustomerContactReportSignatureImage::fromDataUrl($signature);

        $report = $this->reports->findVisible(CustomerContactReportPublicId::fromString($publicId), $visibility);
        if ($report === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }
        $this->assertMaySign($report, $actor, $rowVersion);

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $stored = $this->blobs->put(
            CustomerContactReportBlobPath::signature($now, $report->publicId),
            'image/png',
            $png->bytes(),
        );

        try {
            return $this->reports->saveSignature(new CustomerContactReportSignature(
                publicId: $report->publicId,
                expectedRowVersion: $rowVersion,
                operatorName: $operator,
                signedAt: $now->format('Y-m-d'),
                signatureUrl: $stored->url,
                actorUserId: $actor->userId,
                actorEmail: strtolower(trim($actor->emailAddress)),
                signedAtUtc: $now,
            ));
        } catch (Throwable $failure) {
            $this->blobs->rollback($stored);
            throw $failure;
        }
    }

    private function assertMaySign(
        CustomerContactReportDetails $report,
        AuthenticatedUser $actor,
        string $rowVersion,
    ): void {
        if ($report->route !== CustomerContactReportRoute::ICAR || $report->status !== CustomerContactReportStatus::ASSIGNED) {
            throw CustomerContactReportFileFailure::signatureNotAllowed('A signature can be recorded only on an assigned ICAR Customer Contact.');
        }
        if (!CustomerContactReportAssigneeMatch::isCurrent(
            $actor->userId,
            $actor->emailAddress,
            $report->assignedToUserId,
            $report->assignedTo,
            $report->assignedToEmail,
        )) {
            throw CustomerContactReportFileFailure::signatureNotAllowed('Only the current assignee can record the signature.');
        }
        if (trim((string) $report->icarSignatureUrl) !== '') {
            throw CustomerContactReportFileFailure::signatureExists();
        }
        $expected = CustomerContactReportRowVersion::fromBase64($rowVersion);
        $actual = CustomerContactReportRowVersion::fromBase64($report->rowVersion);
        if (!$expected->equals($actual)) {
            throw CustomerContactReportConflict::stale();
        }
    }
}
