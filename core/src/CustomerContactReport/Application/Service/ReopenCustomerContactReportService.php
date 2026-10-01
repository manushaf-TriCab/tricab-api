<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportReopen;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Shared\Time\Clock;

final class ReopenCustomerContactReportService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'ccNumber',
        'status',
        'assignedTo',
        'assignedBy',
        'assigneeActionTaken',
        'assigneeFindings',
        'closedBy',
        'closedByUserId',
        'closedDate',
        'closedAt',
        'closedAtUtc',
        'icarOperatorName',
        'icarSignedAt',
        'icarSignatureUrl',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(string $publicId, array $payload): CustomerContactReportDetails
    {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        CustomerContactReportPublicId::fromString($publicId);
        CustomerContactReportRowVersion::fromBase64(self::requiredString($payload, 'rowVersion'));

        return $this->reports->reopen(new CustomerContactReportReopen(
            publicId: strtolower(trim($publicId)),
            expectedRowVersion: self::requiredString($payload, 'rowVersion'),
            reopenedAtUtc: $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
        ));
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
}
