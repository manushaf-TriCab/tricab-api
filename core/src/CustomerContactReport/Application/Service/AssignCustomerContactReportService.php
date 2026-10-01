<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use DateTimeZone;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAssignment;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportAssigneeNotFound;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportClientFieldRejected;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportErrorCode;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRoute;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportRowVersion;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Shared\Time\Clock;

final class AssignCustomerContactReportService
{
    /** @var list<string> */
    private const REJECTED_FIELDS = [
        'ccNumber',
        'status',
        'assignedAt',
        'assignedAtUtc',
        'assignedBy',
        'assignedToName',
        'assignedToEmail',
        'assignedToUserId',
    ];

    public function __construct(
        private readonly CustomerContactReportRepository $reports,
        private readonly CustomerContactReportAssigneeDirectory $assignees,
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
        $route = CustomerContactReportRoute::fromString(self::requiredString($payload, 'route'));
        $errorCode = CustomerContactReportErrorCode::fromString(self::requiredString($payload, 'errorCode'));
        $rowVersion = CustomerContactReportRowVersion::fromBase64(self::requiredString($payload, 'rowVersion'));
        $login = self::requiredString($payload, 'assignedTo');
        $assignee = $this->assignees->findActiveStaffByLogin($login);
        if ($assignee === null) {
            throw CustomerContactReportAssigneeNotFound::forLogin($login);
        }

        $before = $this->reports->findVisible(
            CustomerContactReportPublicId::fromString($publicId),
            new CustomerContactReportVisibility(true, $actor->userId, strtolower(trim($actor->emailAddress))),
        );
        $assigned = $this->reports->assign(new CustomerContactReportAssignment(
            publicId: strtolower(trim($publicId)),
            expectedRowVersion: $rowVersion->toBase64(),
            route: $route->toString(),
            errorCode: $errorCode->toString(),
            assigneeUserId: $assignee->userId,
            assigneeLogin: $assignee->login,
            assigneeName: $assignee->displayName,
            assigneeEmail: $assignee->email,
            assignedByEmail: strtolower(trim($actor->emailAddress)),
            assignedAtUtc: $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
        ));
        $this->notifications?->reportAssigned($before, $assigned);

        return $assigned;
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
