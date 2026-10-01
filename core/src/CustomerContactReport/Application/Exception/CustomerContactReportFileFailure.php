<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportFileFailure extends RuntimeException
{
    public const INVALID = 'CUSTOMER_CONTACT_ATTACHMENT_INVALID';

    public const TOO_LARGE = 'CUSTOMER_CONTACT_ATTACHMENT_TOO_LARGE';

    public const UPLOAD_FAILED = 'CUSTOMER_CONTACT_ATTACHMENT_UPLOAD_FAILED';

    public const NOT_ALLOWED = 'CUSTOMER_CONTACT_ATTACHMENT_NOT_ALLOWED';

    public const CREDIT_NOTE_NOT_ALLOWED = 'CUSTOMER_CONTACT_CREDIT_NOTE_NOT_ALLOWED';

    public const SETTLEMENT_NOT_PENDING = 'CUSTOMER_CONTACT_SETTLEMENT_NOT_PENDING';

    public const SETTLEMENT_NOT_ASSIGNEE = 'CUSTOMER_CONTACT_SETTLEMENT_NOT_ASSIGNEE';

    public const SIGNATURE_NOT_ALLOWED = 'CUSTOMER_CONTACT_ICAR_SIGNATURE_NOT_ALLOWED';

    public const SIGNATURE_EXISTS = 'CUSTOMER_CONTACT_ICAR_SIGNATURE_EXISTS';

    public const SIGNATURE_INVALID = 'CUSTOMER_CONTACT_ICAR_SIGNATURE_INVALID';

    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message, 422);
    }

    public static function tooLarge(): self
    {
        return new self(self::TOO_LARGE, 'The file is larger than 15MB.', 422);
    }

    public static function uploadFailed(): self
    {
        return new self(self::UPLOAD_FAILED, 'The file could not be stored.', 503);
    }

    public static function evidenceNotAllowed(string $message): self
    {
        return new self(self::NOT_ALLOWED, $message, 403);
    }

    public static function creditNoteNotAllowed(string $message): self
    {
        return new self(self::CREDIT_NOTE_NOT_ALLOWED, $message, 422);
    }

    public static function settlementNotPending(): self
    {
        return new self(self::SETTLEMENT_NOT_PENDING, 'This credit note has already been raised.', 409);
    }

    public static function settlementNotAssignee(): self
    {
        return new self(self::SETTLEMENT_NOT_ASSIGNEE, 'This credit note is not waiting on you.', 403);
    }

    public static function signatureNotAllowed(string $message): self
    {
        return new self(self::SIGNATURE_NOT_ALLOWED, $message, 403);
    }

    public static function signatureExists(): self
    {
        return new self(self::SIGNATURE_EXISTS, 'This signature is already recorded.', 409);
    }

    public static function signatureInvalid(string $message): self
    {
        return new self(self::SIGNATURE_INVALID, $message, 422);
    }
}
