<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSaveResult;
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
        'restockingAmount',
        'subTotal',
        'gst',
        'creditTotal',
        'creditReviewedBy',
        'creditReviewedDate',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly Clock $clock,
        private readonly ?CustomerContactReportNotifications $notifications = null,
        private readonly ?CustomerContactReportCreditPlanner $planner = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(AuthenticatedUser $actor, array $payload): CustomerContactReportDetails
    {
        return $this->executeWithWarnings($actor, $payload)->report;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function executeWithWarnings(AuthenticatedUser $actor, array $payload): CustomerContactReportSaveResult
    {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        $reportDate = self::date(self::requiredString($payload, 'reportDate'), 'reportDate');
        $location = CustomerContactReportLocation::fromString(self::requiredString($payload, 'location'));

        $orderNo = self::optional($payload, 'orderNo', 32);
        $invoiceNo = self::optional($payload, 'invoiceNo', 32);
        if ($orderNo === null && $invoiceNo === null) {
            throw InvalidCustomerContactReportValue::for('orderNo', 'or invoiceNo is required.');
        }

        $planned = ($this->planner ?? self::unavailablePlanner())->plan(
            $location->toString(),
            self::requiredString($payload, 'creditRequired'),
            self::optional($payload, 'creditType', 20),
            self::optional($payload, 'restockingFeeApplies', 3),
            self::optional($payload, 'addGst', 3),
            self::optionalAmount($payload, 'valueExGst'),
            self::wantedLines($payload),
            [],
        );

        $createdAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $figures = $planned['figures'];
        $productCodes = array_values(array_unique(array_filter(
            array_map(static fn ($line): string => $line->productCode, $planned['lines']),
            static fn (string $code): bool => $code !== '',
        )));
        $productCode = self::optional($payload, 'productCode', 255) ?? implode(', ', $productCodes);

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
            tricabRefTrace: implode(', ', array_filter([$orderNo, $invoiceNo])),
            traceNum: self::optional($payload, 'traceNum', 255),
            conNoteNo: self::optional($payload, 'conNoteNo', 100),
            tricabContact: self::optional($payload, 'tricabContact', 255),
            productCode: self::limited($productCode, 'productCode', 255),
            description: self::limited(self::requiredString($payload, 'description'), 'description', 8000),
            orderNo: $orderNo,
            invoiceNo: $invoiceNo,
            creditRequired: $planned['creditRequired'],
            creditType: $planned['creditType'],
            restockingFeeApplies: $planned['restockingFeeApplies'],
            quotedAmount: self::optional($payload, 'quotedAmount', 100),
            invoicedAmount: self::optional($payload, 'invoicedAmount', 100),
            addGst: $planned['addGst'],
            requestedActions: self::optional($payload, 'requestedActions', 8000),
            figures: $figures,
            lines: $planned['lines'],
        ));
        $this->notifications?->reportSubmitted($created);

        return new CustomerContactReportSaveResult($created, $planned['warnings']);
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

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalAmount(array $payload, string $field): ?string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
            return null;
        }
        $value = $payload[$field];
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw InvalidCustomerContactReportValue::for($field, 'must be an amount.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>|null
     */
    private static function wantedLines(array $payload): ?array
    {
        if (!array_key_exists('lines', $payload) || $payload['lines'] === null) {
            return null;
        }
        $lines = $payload['lines'];
        if (!is_array($lines) || count($lines) > 200) {
            throw InvalidCustomerContactReportValue::for('lines', 'must be a list of at most 200 lines.');
        }
        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw InvalidCustomerContactReportValue::for('lines', 'must be a list of objects.');
            }
            $record = [];
            foreach ($line as $key => $value) {
                if (is_string($key)) {
                    $record[$key] = $value;
                }
            }
            $out[] = $record;
        }

        return $out;
    }

    private static function unavailablePlanner(): CustomerContactReportCreditPlanner
    {
        return new CustomerContactReportCreditPlanner(
            new CustomerContactReportLineResolver(
                new CustomerContactFourJsLookup(new UnavailableCustomerContactFourJsGateway()),
            ),
        );
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
