<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalDecision;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalOutcome;
use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportApprovalFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportApprovalSeparation;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportAssigneeMatch;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportApprovalKind;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class DecideCustomerContactReportApprovalService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'status',
        'requestedBy',
        'approver',
        'approverName',
        'approverEmail',
        'approverUserId',
        'decidedAt',
        'decidedAtUtc',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportAssigneeDirectory $staff,
        private readonly Clock $clock,
        private readonly CustomerContactReportAccountsLogin $accountsLogin,
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

        $approvalKind = CustomerContactReportApprovalKind::decidable($kind);
        CustomerContactReportPublicId::fromString($publicId);
        $rowVersion = self::requiredString($payload, 'rowVersion');
        CustomerContactReportRowVersion::fromBase64($rowVersion);
        $decision = strtolower(self::requiredString($payload, 'decision'));
        if (!in_array($decision, ['approved', 'declined'], true)) {
            throw InvalidCustomerContactReportValue::for('decision', 'must be approved or declined.');
        }
        $note = self::optionalNote($payload);
        if ($decision === 'declined' && $note === null) {
            throw InvalidCustomerContactReportValue::for('note', 'is required when an approval is declined.');
        }

        $this->assertMayDecide($actor, $publicId, $approvalKind->toString());

        $decidedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $settlement = null;
        if ($approvalKind->isCredit() && $decision === 'approved') {
            $settlement = $this->accountsAssignee();
        }

        $outcome = $this->reports->decideApproval(new CustomerContactReportApprovalDecision(
            publicId: strtolower(trim($publicId)),
            kind: $approvalKind->toString(),
            expectedRowVersion: $rowVersion,
            decision: $decision,
            note: $note,
            actorUserId: $actor->userId,
            actorEmail: strtolower(trim($actor->emailAddress)),
            decidedAtUtc: $decidedAt,
            settlementApproverUserId: $settlement?->userId,
            settlementApproverLogin: $settlement?->login,
            settlementApproverName: $settlement?->displayName,
            settlementApproverEmail: $settlement?->email,
        ));
        if ($outcome->settlement !== null) {
            $this->notifications?->approvalRequested($outcome->report, $outcome->settlement);
        }
        $this->notifications?->approvalDecided($outcome->report, $outcome->approval);

        return $outcome;
    }

    private function accountsAssignee(): ResolvedCustomerContactAssignee
    {
        $login = strtolower(trim($this->accountsLogin->login));
        if ($login === '') {
            throw CustomerContactReportApprovalFailure::configuration(
                'Credit-note settlement cannot be raised because CUSTOMER_CONTACT_ACCOUNTS_LOGIN is not configured.',
            );
        }
        $person = $this->staff->findActiveStaffByLogin($login);
        if ($person === null) {
            throw CustomerContactReportApprovalFailure::configuration(
                'Credit-note settlement cannot be raised because the configured Accounts login cannot be resolved.',
            );
        }

        return $person;
    }

    private function assertMayDecide(AuthenticatedUser $actor, string $publicId, string $kind): void
    {
        if (!in_array(Module::PERMISSION_APPROVE, $actor->permissions, true)) {
            throw CustomerContactReportApprovalFailure::approveRequired();
        }

        foreach ($this->reports->approvalsFor(strtolower(trim($publicId))) as $approval) {
            if ($approval->kind !== $kind) {
                continue;
            }
            if (!CustomerContactReportAssigneeMatch::isCurrent(
                $actor->userId,
                $actor->emailAddress,
                $approval->approverUserId,
                $approval->approver,
                $approval->approverEmail,
            )) {
                throw CustomerContactReportApprovalFailure::notApprover();
            }
            if (CustomerContactReportApprovalSeparation::samePerson(
                $actor->userId,
                $actor->emailAddress,
                $approval->requestedByUserId,
                $approval->requestedBy,
                $approval->requestedBy,
            )) {
                throw CustomerContactReportApprovalFailure::selfApproval();
            }

            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalNote(array $payload): ?string
    {
        if (!array_key_exists('note', $payload)) {
            return null;
        }
        $value = $payload['note'];
        if (!is_string($value)) {
            throw InvalidCustomerContactReportValue::for('note', 'must be text.');
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
