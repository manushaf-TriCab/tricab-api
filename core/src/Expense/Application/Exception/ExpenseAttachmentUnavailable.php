<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;

final class ExpenseAttachmentUnavailable extends RuntimeException
{
    public static function storage(): self
    {
        return new self('Expense attachment storage is unavailable.');
    }

    public static function notFound(): self
    {
        return new self('Receipt attachment was not found.');
    }

    public static function legacyDualRead(): self
    {
        return new self('Legacy attachment must be migrated before a SAS download can be issued.');
    }

    public static function invalidFile(): self
    {
        return new self('Attachment file type or size is not allowed.');
    }

    public static function missingObject(): self
    {
        return new self('Uploaded attachment object was not found.');
    }
}
