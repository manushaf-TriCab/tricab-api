<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\NewCustomerContactReport;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportLocation;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class CreateCustomerContactReportService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'status',
        'ccNumber',
        'route',
        'errorCode',
        'assignedTo',
        'assignedToName',
        'assignedToEmail',
        'assignedBy',
        'closedBy',
        'createdByEmail',
        'createdByName',
        'createdByUserId',
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
    public function execute(AuthenticatedUser $actor, array $payload): CustomerContactReportDetails
    {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        $reportDate = self::date(self::requiredString($payload, 'reportDate'), 'reportDate');
        $location = CustomerContactReportLocation::fromString(self::requiredString($payload, 'location'));

        $createdAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $created = $this->reports->insert(new NewCustomerContactReport(
            publicId: CustomerContactReportPublicId::generate()->toString(),
            createdByUserId: $actor->userId,
            createdByEmail: strtolower(trim($actor->emailAddress)),
            createdByName: self::displayName($actor),
            createdAtUtc: $createdAt,
            reportDate: $reportDate,
            location: $location->toString(),
            customerCode: self::limited(self::requiredString($payload, 'customerCode'), 'customerCode', 50),
            customerContact: self::optional($payload, 'customerContact', 255),
            tricabRefTrace: self::optional($payload, 'tricabRefTrace', 255),
            traceNum: self::optional($payload, 'traceNum', 255),
            conNoteNo: self::optional($payload, 'conNoteNo', 100),
            tricabContact: self::optional($payload, 'tricabContact', 255),
            productCode: self::limited(self::requiredString($payload, 'productCode'), 'productCode', 255),
            description: self::limited(self::requiredString($payload, 'description'), 'description', 8000),
        ));
        $this->notifications?->reportSubmitted($created);

        return $created;
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
     * @param array<string, mixed> $payload
     */
    private static function optional(array $payload, string $field, int $max): ?string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }
        if (!is_string($payload[$field])) {
            throw InvalidCustomerContactReportValue::for($field, 'must be a string.');
        }
        $value = trim($payload[$field]);
        if ($value === '') {
            return null;
        }

        return self::limited($value, $field, $max);
    }

    private static function limited(string $value, string $field, int $max): string
    {
        if (mb_strlen($value) > $max) {
            throw InvalidCustomerContactReportValue::for($field, 'is too long.');
        }

        return $value;
    }

    private static function displayName(AuthenticatedUser $actor): string
    {
        $name = trim($actor->displayName);

        return $name !== '' ? $name : strtolower(trim($actor->emailAddress));
    }

    private static function date(string $value, string $field): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw InvalidCustomerContactReportValue::for($field, 'must be a date (YYYY-MM-DD).');
        }

        return $parsed;
    }
}
