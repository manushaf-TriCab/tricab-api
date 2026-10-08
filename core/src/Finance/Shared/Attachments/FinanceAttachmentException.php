<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

use RuntimeException;

/** Base for every refusal of the finance file store. */
abstract class FinanceAttachmentException extends RuntimeException
{
}
