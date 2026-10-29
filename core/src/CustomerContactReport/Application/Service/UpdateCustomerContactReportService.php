<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportSaveResult;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportUpdate;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAccessDenied;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportLine;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportErrorCode;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportLocation;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

/**
 * Autosave and amend. Port of the live cc_update_report and cc_amend_lines.
 *
 * Who may write what (live cc_writable_fields):
 *   coordinator (ASSIGN)  intake, credit, form and office fields, at any status
 *   originator            intake, credit and form fields until the report is closed
 *   assignee              action taken and findings, only while assigned
 * ICAR operator and signature have their own write-once endpoint. The server owns
 * the CC number, route, status, assignee, closure and every money total.
 *
 * Every write carries the report row version. A stale version is a conflict
 * and nothing is written. That is deliberate: live autosave overwrites silently.
 */
final class UpdateCustomerContactReportService
{
    private const INTAKE = [
        'reportDate', 'location', 'customerCode', 'customerContact', 'orderNo', 'invoiceNo',
        'traceNum', 'conNoteNo', 'tricabContact', 'productCode', 'description', 'requestedActions',
        'quotedAmount', 'invoicedAmount',
    ];

    private const CREDIT = ['creditRequired', 'creditType', 'restockingFeeApplies', 'addGst', 'valueExGst', 'lines'];

    private const FORM_TEXT = [
        'schedulingInformed' => 20, 'returnArrangedBy' => 255, 'actionsTaken' => 8000,
        'rootCause' => 8000, 'correctiveAction' => 8000, 'notes' => 8000,
    ];

    private const FORM_CHECK = ['goodsReturned', 'carrierBooked', 'creditRaised', 'goodsReplaced', 'carRaised'];

    private const FORM_DATE = [
        'expectedReturnDate', 'goodsReturnedDate', 'carrierBookedDate', 'creditRaisedDate',
        'goodsReplacedDate', 'carRaisedDate',
    ];

    private const OFFICE = ['errorCode', 'investigationDueDate', 'ccAssessedBy'];

    private const ASSIGNEE = ['assigneeActionTaken', 'assigneeFindings'];

    /** Always the server's: a client naming one of these is refused, not ignored. */
    private const SERVER_OWNED = [
        'status', 'ccNumber', 'route', 'assignedTo', 'assignedToName', 'assignedToEmail', 'assignedToUserId',
        'assignedBy', 'assignedAtUtc', 'createdByEmail', 'createdByName', 'createdByUserId', 'createdAtUtc',
        'closedBy', 'closedByUserId', 'closedDate', 'closedAtUtc', 'creditReviewedBy', 'creditReviewedDate',
        'icarOperatorName', 'icarSignedAt', 'icarSignatureUrl', 'restockingAmount', 'subTotal', 'gst',
        'creditTotal', 'tricabRefTrace', 'publicId', 'reportId', 'updatedAtUtc', 'assigneeCompletedAtUtc',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly Clock $clock,
        private readonly CustomerContactReportCreditPlanner $planner,
        private readonly ?CustomerContactReportNotifications $notifications = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(AuthenticatedUser $actor, string $publicId, array $payload): CustomerContactReportSaveResult
    {
        CustomerContactReportPublicId::fromString($publicId);
        $rowVersion = self::requiredString($payload, 'rowVersion');
        CustomerContactReportRowVersion::fromBase64($rowVersion);
        unset($payload['rowVersion']);

        $known = array_merge(
            self::INTAKE,
            self::CREDIT,
            array_keys(self::FORM_TEXT),
            self::FORM_CHECK,
            self::FORM_DATE,
            self::OFFICE,
            self::ASSIGNEE,
        );
        foreach (array_keys($payload) as $field) {
            if (in_array($field, self::SERVER_OWNED, true) || !in_array($field, $known, true)) {
                throw CustomerContactReportClientFieldRejected::forField((string) $field);
            }
        }
        if ($payload === []) {
            throw InvalidCustomerContactReportValue::for('body', 'has nothing to save.');
        }

        $report = $this->reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new CustomerContactReportVisibility(
                in_array(Module::PERMISSION_READ_ALL, $actor->permissions, true),
                $actor->userId,
                $actor->emailAddress,
            ),
        );
        if ($report === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        $closed = $report->status === 'closed';
        $coordinator = in_array(Module::PERMISSION_ASSIGN, $actor->permissions, true);
        $originator = ($actor->userId > 0 && $report->createdByUserId === $actor->userId)
            || strtolower(trim($report->createdByEmail)) === strtolower(trim($actor->emailAddress));
        $assignee = $report->status === 'assigned' && CustomerContactReportAssigneeMatch::isCurrent(
            $actor->userId,
            $actor->emailAddress,
            $report->assignedToUserId,
            $report->assignedTo,
            $report->assignedToEmail,
        );

        $mayAmend = ($coordinator || $originator) && !$closed;
        $mayForm = $coordinator || $mayAmend;
        $allowed = [];
        if ($mayAmend) {
            $allowed = array_merge($allowed, self::INTAKE, self::CREDIT);
        }
        if ($mayForm) {
            $allowed = array_merge($allowed, array_keys(self::FORM_TEXT), self::FORM_CHECK, self::FORM_DATE);
        }
        if ($coordinator) {
            $allowed = array_merge($allowed, self::OFFICE);
        }
        if ($assignee || ($coordinator && !$closed)) {
            $allowed = array_merge($allowed, self::ASSIGNEE);
        }
        if ($allowed === []) {
            throw new CustomerContactReportAccessDenied('You cannot edit this Customer Contact.');
        }
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new CustomerContactReportAccessDenied(
                    $closed && in_array($field, array_merge(self::INTAKE, self::CREDIT), true)
                        ? 'A closed Customer Contact cannot be amended.'
                        : 'You cannot edit ' . $field . ' on this Customer Contact.',
                );
            }
        }

        $changes = [];
        $location = $report->location;
        if (array_key_exists('location', $payload)) {
            $location = CustomerContactReportLocation::fromString(self::string($payload, 'location'))->toString();
            $changes['location'] = $location;
        }
        if (array_key_exists('reportDate', $payload)) {
            $changes['reportDate'] = self::date(self::string($payload, 'reportDate'), 'reportDate');
        }
        foreach (['customerCode' => 50, 'description' => 8000] as $field => $max) {
            if (array_key_exists($field, $payload)) {
                $changes[$field] = self::limited(self::string($payload, $field), $field, $max);
                if ($changes[$field] === '') {
                    throw InvalidCustomerContactReportValue::for($field, 'is required.');
                }
            }
        }
        foreach ([
            'customerContact' => 255, 'traceNum' => 255, 'conNoteNo' => 100, 'tricabContact' => 255,
            'requestedActions' => 8000, 'quotedAmount' => 100, 'invoicedAmount' => 100,
            'ccAssessedBy' => 255, 'assigneeActionTaken' => 8000, 'assigneeFindings' => 8000,
        ] + self::FORM_TEXT as $field => $max) {
            if (array_key_exists($field, $payload)) {
                $changes[$field] = self::nullableText($payload, $field, $max);
            }
        }
        if (array_key_exists('productCode', $payload)) {
            $changes['productCode'] = self::limited(self::nullableText($payload, 'productCode', 255) ?? '', 'productCode', 255);
        }
        foreach (self::FORM_CHECK as $field) {
            if (array_key_exists($field, $payload)) {
                $value = self::nullableText($payload, $field, 10);
                if ($value !== null && !in_array($value, ['yes', 'na'], true)) {
                    throw InvalidCustomerContactReportValue::for($field, 'must be yes or na.');
                }
                $changes[$field] = $value;
            }
        }
        foreach (array_merge(self::FORM_DATE, ['investigationDueDate']) as $field) {
            if (array_key_exists($field, $payload)) {
                $value = self::nullableText($payload, $field, 10);
                $changes[$field] = $value === null ? null : self::date($value, $field)->format('Y-m-d');
            }
        }
        if (array_key_exists('errorCode', $payload)) {
            $changes['errorCode'] = CustomerContactReportErrorCode::fromString(self::string($payload, 'errorCode'))->toString();
        }

        $orderNo = array_key_exists('orderNo', $payload) ? self::nullableText($payload, 'orderNo', 32) : $report->orderNo;
        $invoiceNo = array_key_exists('invoiceNo', $payload) ? self::nullableText($payload, 'invoiceNo', 32) : $report->invoiceNo;
        if (array_key_exists('orderNo', $payload) || array_key_exists('invoiceNo', $payload)) {
            if ($orderNo === null && $invoiceNo === null) {
                throw InvalidCustomerContactReportValue::for('orderNo', 'or invoiceNo must stay on the report.');
            }
            $changes['orderNo'] = $orderNo;
            $changes['invoiceNo'] = $invoiceNo;
            $changes['tricabRefTrace'] = implode(', ', array_filter([$orderNo, $invoiceNo]));
        }

        $warnings = [];
        $newLines = null;
        $creditChanged = false;
        $touchesCredit = count(array_intersect(array_keys($payload), self::CREDIT)) > 0
            || array_key_exists('location', $payload);
        if ($touchesCredit) {
            $wanted = array_key_exists('lines', $payload) ? self::wantedLines($payload['lines']) : null;
            $typed = array_key_exists('valueExGst', $payload)
                ? self::nullableText($payload, 'valueExGst', 32)
                : $report->valueExGst;
            $planned = $this->planner->plan(
                $location,
                array_key_exists('creditRequired', $payload) ? self::string($payload, 'creditRequired') : ($report->creditRequired ?? 'No'),
                array_key_exists('creditType', $payload) ? self::nullableText($payload, 'creditType', 20) : $report->creditType,
                array_key_exists('restockingFeeApplies', $payload) ? self::nullableText($payload, 'restockingFeeApplies', 3) : $report->restockingFeeApplies,
                array_key_exists('addGst', $payload) ? self::nullableText($payload, 'addGst', 3) : $report->addGst,
                $typed,
                $wanted,
                $report->lines,
            );
            $warnings = $planned['warnings'];
            $figures = $planned['figures'];
            $changes['creditRequired'] = $planned['creditRequired'];
            $changes['creditType'] = $planned['creditType'];
            $changes['restockingFeeApplies'] = $planned['restockingFeeApplies'];
            $changes['addGst'] = $planned['addGst'];
            $changes['valueExGst'] = $figures?->valueExGst;
            $changes['restockingAmount'] = $figures?->restockingAmount;
            $changes['subTotal'] = $figures?->subTotal;
            $changes['gst'] = $figures?->gst;
            $changes['creditTotal'] = $figures?->creditTotal;
            if ($planned['linesReplaced']) {
                $newLines = $planned['lines'];
                $changes['lines'] = $newLines;
            }
            $creditChanged = self::creditDiffers($report, $changes)
                || ($newLines !== null && self::signature($newLines) !== self::signature($report->lines));
        }

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $outcome = $this->reports->updateReport(new CustomerContactReportUpdate(
            publicId: strtolower(trim($publicId)),
            expectedRowVersion: $rowVersion,
            changes: $changes,
            lines: $newLines,
            creditChanged: $creditChanged,
            updatedAtUtc: $now,
        ));
        if ($outcome->creditReset && $outcome->creditApproval !== null) {
            $this->notifications?->creditAmended($outcome->report, $outcome->creditApproval, $actor->displayName !== '' ? $actor->displayName : $actor->emailAddress);
        }

        return new CustomerContactReportSaveResult($outcome->report, $warnings, $outcome->creditReset);
    }

    private static function scalarText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function scalarFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function creditDiffers(\TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails $report, array $changes): bool
    {
        foreach (['creditRequired', 'creditType', 'restockingFeeApplies', 'addGst'] as $field) {
            if (trim(self::scalarText($report->{$field} ?? '')) !== trim(self::scalarText($changes[$field] ?? ''))) {
                return true;
            }
        }
        foreach (['valueExGst', 'restockingAmount', 'subTotal', 'gst', 'creditTotal'] as $field) {
            $old = $report->{$field};
            $new = $changes[$field] ?? null;
            if ($old === null || $new === null) {
                if ($old !== $new) {
                    return true;
                }
                continue;
            }
            if (abs(self::scalarFloat($old) - self::scalarFloat($new)) > 0.004) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<CustomerContactReportLine> $lines
     */
    private static function signature(array $lines): string
    {
        $parts = array_map(static fn (CustomerContactReportLine $l): string => $l->signature(), $lines);
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function wantedLines(mixed $lines): array
    {
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
    private static function string(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw InvalidCustomerContactReportValue::for($field, 'must be text.');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function nullableText(array $payload, string $field, int $max): ?string
    {
        $value = $payload[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw InvalidCustomerContactReportValue::for($field, 'must be text.');
        }
        $value = trim($value);

        return $value === '' ? null : self::limited($value, $field, $max);
    }

    private static function limited(string $value, string $field, int $max): string
    {
        if (mb_strlen($value) > $max) {
            throw InvalidCustomerContactReportValue::for($field, 'is too long.');
        }

        return $value;
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
