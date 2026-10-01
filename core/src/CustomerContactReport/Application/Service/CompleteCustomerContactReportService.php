<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportCompletion;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class CompleteCustomerContactReportService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'ccNumber',
        'status',
        'assignedTo',
        'assignedBy',
        'assignedAt',
        'assignedAtUtc',
        'assignedToName',
        'assignedToEmail',
        'assignedToUserId',
        'completedAt',
        'completedAtUtc',
        'assigneeCompletedAtUtc',
        'icarOperatorName',
        'icarSignedAt',
        'icarSignatureUrl',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly Clock $clock,
        private readonly ?CustomerContactReportNotifications $notifications = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(AuthenticatedUser $actor, string $publicId, array $payload): CustomerContactReportDetails
    {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        CustomerContactReportPublicId::fromString($publicId);
        CustomerContactReportRowVersion::fromBase64(self::requiredString($payload, 'rowVersion'));
        $completedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $completed = $this->reports->complete(new CustomerContactReportCompletion(
            publicId: strtolower(trim($publicId)),
            expectedRowVersion: self::requiredString($payload, 'rowVersion'),
            actorUserId: $actor->userId,
            actorEmail: strtolower(trim($actor->emailAddress)),
            actionProvided: array_key_exists('assigneeActionTaken', $payload),
            assigneeActionTaken: self::optionalText($payload, 'assigneeActionTaken'),
            findingsProvided: array_key_exists('assigneeFindings', $payload),
            assigneeFindings: self::optionalText($payload, 'assigneeFindings'),
            completedAtUtc: $completedAt,
        ));
        $this->notifications?->reportCompleted($completed);

        return $completed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw InvalidCustomerContactReportValue::for($field, 'is required.');
        }

        return trim($value);
    }

    /**
     * Legacy complete writes a posted narrative, including a blank one, and
     * leaves the column alone when the form did not send it. Blank text is
     * stored as null, matching the API's empty-text convention.
     *
     * @param array<string, mixed> $payload
     */
    private static function optionalText(array $payload, string $field): ?string
    {
        if (!array_key_exists($field, $payload)) {
            return null;
        }

        $value = $payload[$field];
        if (!is_string($value)) {
            throw InvalidCustomerContactReportValue::for($field, 'must be text.');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
