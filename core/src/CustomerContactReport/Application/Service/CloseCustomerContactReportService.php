<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportClosure;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class CloseCustomerContactReportService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'ccNumber',
        'status',
        'assignedTo',
        'assignedBy',
        'closedBy',
        'closedByUserId',
        'closedDate',
        'closedAt',
        'closedAtUtc',
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
        $closedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $closedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $closedAt->format('Y-m-d'), new DateTimeZone('UTC'));
        if ($closedDate === false) {
            throw InvalidCustomerContactReportValue::for('closedDate', 'could not be derived.');
        }

        $closed = $this->reports->close(new CustomerContactReportClosure(
            publicId: strtolower(trim($publicId)),
            expectedRowVersion: self::requiredString($payload, 'rowVersion'),
            closedBy: strtolower(trim($actor->emailAddress)),
            closedByUserId: $actor->userId,
            closedDate: $closedDate,
            closedAtUtc: $closedAt,
        ));
        $this->notifications?->reportClosed($closed);

        return $closed;
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
