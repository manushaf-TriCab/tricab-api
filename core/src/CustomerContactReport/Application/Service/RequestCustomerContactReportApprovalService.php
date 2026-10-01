<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest;
use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactCreditEscalation;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportApprovalSeparation;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportMoney;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportApprovalKind;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class RequestCustomerContactReportApprovalService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'status',
        'requestedBy',
        'requestedByUserId',
        'requestedAt',
        'requestedAtUtc',
        'approverName',
        'approverEmail',
        'approverUserId',
        'decidedAt',
        'decidedAtUtc',
        'note',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportAssigneeDirectory $staff,
        private readonly CustomerContactReportReportingLine $reportingLine,
        private readonly Clock $clock,
        private readonly ?CustomerContactReportNotifications $notifications = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(AuthenticatedUser $actor, string $publicId, string $kind, array $payload): CustomerContactReportApprovalOutcome
    {
        foreach (self::REJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                throw CustomerContactReportClientFieldRejected::forField($field);
            }
        }

        $approvalKind = CustomerContactReportApprovalKind::requestable($kind);
        CustomerContactReportPublicId::fromString($publicId);
        $rowVersion = self::requiredString($payload, 'rowVersion');
        CustomerContactReportRowVersion::fromBase64($rowVersion);
        $report = $this->reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, $actor->userId, $actor->emailAddress),
        );
        if ($report === null) {
            throw CustomerContactReportNotFound::forPublicId($publicId);
        }

        $writeCreditFigures = false;
        $valueExGst = null;
        $restockingAmount = null;
        $subTotal = null;
        $gst = null;
        $creditTotal = null;
        if ($approvalKind->isCredit()) {
            $figures = $this->creditFigures($payload, $report->creditTotal);
            $approver = $this->creditApprover($report->createdByEmail, $figures['total'], $payload);
            $writeCreditFigures = $figures['write'];
            $valueExGst = $figures['valueExGst'];
            $restockingAmount = $figures['restockingAmount'];
            $subTotal = $figures['subTotal'];
            $gst = $figures['gst'];
            $creditTotal = $figures['creditTotal'];
        } else {
            $approver = $this->pickedApprover($payload);
        }
        $this->rejectSelfApproval($actor, $approver);

        $outcome = $this->reports->requestApproval(new CustomerContactReportApprovalRequest(
            publicId: strtolower(trim($publicId)),
            expectedReportRowVersion: $rowVersion,
            kind: $approvalKind->toString(),
            approverLogin: $approver->login,
            approverUserId: $approver->userId,
            approverName: $approver->displayName,
            approverEmail: $approver->email,
            requestedBy: strtolower(trim($actor->emailAddress)),
            requestedByUserId: $actor->userId,
            requestedAtUtc: $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
            writeCreditFigures: $writeCreditFigures,
            valueExGst: $valueExGst,
            restockingAmount: $restockingAmount,
            subTotal: $subTotal,
            gst: $gst,
            creditTotal: $creditTotal,
        ));
        $this->notifications?->approvalRequested($outcome->report, $outcome->approval);

        return $outcome;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{total: CustomerContactReportMoney, write: bool, valueExGst: ?string, restockingAmount: ?string, subTotal: ?string, gst: ?string, creditTotal: ?string}
     */
    private function creditFigures(array $payload, ?string $storedTotal): array
    {
        $write = false;
        $parsed = [];
        foreach (['valueExGst', 'restockingAmount', 'subTotal', 'gst', 'creditTotal'] as $field) {
            if (!array_key_exists($field, $payload)) {
                $parsed[$field] = null;
                continue;
            }
            $write = true;
            $raw = $payload[$field];
            if (!is_string($raw)) {
                throw InvalidCustomerContactReportValue::for($field, 'must be text.');
            }
            $parsed[$field] = CustomerContactReportMoney::fromDecimal($raw, $field)->toDecimal();
        }

        $totalSource = $parsed['creditTotal'] ?? $storedTotal;
        if ($totalSource === null || trim($totalSource) === '') {
            throw InvalidCustomerContactReportValue::for('creditTotal', 'is required before a credit can be sent for approval.');
        }
        $total = CustomerContactReportMoney::fromDecimal($totalSource, 'creditTotal');
        if (!$total->isPositive()) {
            throw InvalidCustomerContactReportValue::for('creditTotal', 'must be greater than zero.');
        }

        return [
            'total' => $total,
            'write' => $write,
            'valueExGst' => $parsed['valueExGst'],
            'restockingAmount' => $parsed['restockingAmount'],
            'subTotal' => $parsed['subTotal'],
            'gst' => $parsed['gst'],
            'creditTotal' => $parsed['creditTotal'] ?? $total->toDecimal(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function creditApprover(string $createdByEmail, CustomerContactReportMoney $total, array $payload): ResolvedCustomerContactAssignee
    {
        if (!$this->reportingLine->isAvailable()) {
            throw CustomerContactReportApprovalFailure::configuration(
                'Credit approval cannot be routed because staff reporting lines are not available.',
            );
        }

        $raiser = strtolower(trim($createdByEmail));
        $manager = $raiser === '' ? null : $this->reportingLine->managerOf($raiser);
        if ($manager !== null && CustomerContactCreditEscalation::climbsOneLevel($total)) {
            $above = $this->reportingLine->managerOf($manager->login);
            if ($above !== null) {
                $manager = $above;
            }
        }
        if ($manager !== null) {
            return $manager;
        }

        if (!array_key_exists('approver', $payload)) {
            throw CustomerContactReportApprovalFailure::configuration(
                'No manager is on file for whoever raised this Customer Contact, so there is nobody to send the credit to.',
            );
        }

        return $this->pickedApprover($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pickedApprover(array $payload): ResolvedCustomerContactAssignee
    {
        $login = self::requiredString($payload, 'approver');
        $person = $this->staff->findActiveStaffByLogin($login);
        if ($person === null) {
            throw CustomerContactReportApprovalFailure::approverNotFound($login);
        }

        return $person;
    }

    private function rejectSelfApproval(AuthenticatedUser $actor, ResolvedCustomerContactAssignee $approver): void
    {
        if (CustomerContactReportApprovalSeparation::samePerson(
            $actor->userId,
            $actor->emailAddress,
            $approver->userId,
            $approver->login,
            $approver->email,
        )) {
            throw CustomerContactReportApprovalFailure::selfApproval();
        }
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
