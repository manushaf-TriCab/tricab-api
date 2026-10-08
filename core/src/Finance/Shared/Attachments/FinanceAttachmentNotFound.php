<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

/**
 * No such file for this app and caller. The same answer whether the file does not
 * exist, belongs to another app, or sits on a record the caller may not see, so a
 * caller cannot tell the three apart.
 */
final class FinanceAttachmentNotFound extends FinanceAttachmentException
{
    public function __construct()
    {
        parent::__construct('No such file.');
    }
}
