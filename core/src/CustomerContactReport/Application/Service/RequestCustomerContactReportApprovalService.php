<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportNotFound;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
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
        'approver',
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
            $approver = $this->creditApprover($report);
            $writeCreditFigures = $figures['write'];
            $valueExGst = $figures['valueExGst'];
            $restockingAmount = $figures['restockingAmount'];
            $subTotal = $figures['subTotal'];
            $gst = $figures['gst'];
            $creditTotal = $figures['creditTotal'];
        } else {
            throw InvalidCustomerContactReportValue::for('kind', 'must be credit.');
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
     * @return array{total: ?CustomerContactReportMoney, write: bool, valueExGst: ?string, restockingAmount: ?string, subTotal: ?string, gst: ?string, creditTotal: ?string}
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
        $total = $totalSource === null || trim($totalSource) === ''
            ? null
            : CustomerContactReportMoney::fromDecimal($totalSource, 'creditTotal');

        return [
            'total' => $total,
            'write' => $write,
            'valueExGst' => $parsed['valueExGst'],
            'restockingAmount' => $parsed['restockingAmount'],
            'subTotal' => $parsed['subTotal'],
            'gst' => $parsed['gst'],
            'creditTotal' => $parsed['creditTotal'] ?? $total?->toDecimal(),
        ];
    }

    /**
     * Credit is owned by whoever is assigned the report. Finance (settlement)
     * is raised when the credit is approved. There is no manager hop and no
     * amount threshold, matching the live rule since 2026-10-15.
     */
    private function creditApprover(CustomerContactReportDetails $report): ResolvedCustomerContactAssignee
    {
        if (!in_array($report->status, ['assigned', 'completed'], true)
            || $report->assignedTo === null
            || $report->assignedToUserId === null
        ) {
            throw CustomerContactReportApprovalFailure::configuration(
                'A credit can be requested only after the Customer Contact is assigned. The assignee owns the credit.',
            );
        }

        return new ResolvedCustomerContactAssignee(
            $report->assignedToUserId,
            $report->assignedTo,
            $report->assignedToName ?? $report->assignedTo,
            $report->assignedToEmail ?? $report->assignedTo,
        );
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
