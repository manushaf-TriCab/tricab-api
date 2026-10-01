<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportConflict extends RuntimeException
{
    public const STALE = 'CUSTOMER_CONTACT_REPORT_CONFLICT';

    public const NOT_ASSIGNABLE = 'CUSTOMER_CONTACT_REPORT_NOT_ASSIGNABLE';

    public const NOT_COMPLETABLE = 'CUSTOMER_CONTACT_REPORT_NOT_COMPLETABLE';

    public const NOT_CLOSABLE = 'CUSTOMER_CONTACT_REPORT_NOT_CLOSABLE';

    public const NOT_REOPENABLE = 'CUSTOMER_CONTACT_REPORT_NOT_REOPENABLE';

    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function stale(): self
    {
        return new self(self::STALE, 'The Customer Contact report was updated by someone else.');
    }

    public static function notAssignable(string $status): self
    {
        return new self(self::NOT_ASSIGNABLE, 'A Customer Contact report in status ' . $status . ' cannot be assigned.');
    }

    public static function notCompletable(string $status): self
    {
        return new self(self::NOT_COMPLETABLE, 'A Customer Contact report in status ' . $status . ' cannot be completed.');
    }

    public static function notClosable(string $status): self
    {
        return new self(self::NOT_CLOSABLE, 'A Customer Contact report in status ' . $status . ' cannot be closed.');
    }

    public static function notReopenable(string $status): self
    {
        return new self(self::NOT_REOPENABLE, 'A Customer Contact report in status ' . $status . ' cannot be reopened.');
    }
}
