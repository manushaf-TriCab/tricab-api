<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportApprovalFailure extends RuntimeException
{
    public const NOT_FOUND = 'CUSTOMER_CONTACT_APPROVAL_NOT_FOUND';

    public const NOT_PENDING = 'CUSTOMER_CONTACT_APPROVAL_NOT_PENDING';

    public const CONFLICT = 'CUSTOMER_CONTACT_APPROVAL_CONFLICT';

    public const NOT_APPROVER = 'CUSTOMER_CONTACT_APPROVAL_NOT_APPROVER';

    public const APPROVER_NOT_FOUND = 'CUSTOMER_CONTACT_APPROVER_NOT_FOUND';

    public const CONFIGURATION = 'CUSTOMER_CONTACT_APPROVAL_CONFIGURATION_ERROR';

    public const SELF = 'CUSTOMER_CONTACT_APPROVAL_SELF';

    public const PERMISSION = 'INSUFFICIENT_PERMISSION';

    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $kind): self
    {
        return new self(self::NOT_FOUND, 'No ' . $kind . ' approval exists for this Customer Contact report.', 404);
    }

    public static function notPending(string $status): self
    {
        return new self(self::NOT_PENDING, 'This approval is ' . $status . ' and cannot be changed.', 409);
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT, 'The approval was updated by someone else.', 409);
    }

    public static function notApprover(): self
    {
        return new self(self::NOT_APPROVER, 'Only the recorded approver can decide this approval.', 403);
    }

    public static function approverNotFound(string $login): self
    {
        return new self(self::APPROVER_NOT_FOUND, 'No active staff member matches approver ' . $login . '.', 422);
    }

    public static function configuration(string $message): self
    {
        return new self(self::CONFIGURATION, $message, 503);
    }

    public static function selfApproval(): self
    {
        return new self(
            self::SELF,
            'The person who requests a remedy cannot approve that same request.',
            403,
        );
    }

    public static function approveRequired(): self
    {
        return new self(
            self::PERMISSION,
            'CUSTOMER_CONTACT.APPROVE is required to decide this approval.',
            403,
        );
    }
}
