<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** An upload refused by the limits or the type check. `code` is the endpoint's error code; `status` its HTTP status. */
final class NprAttachmentRejected extends RuntimeException
{
    public const TOO_LARGE = 'NPR_ATTACHMENT_TOO_LARGE';
    public const REQUEST_TOO_LARGE = 'NPR_ATTACHMENT_REQUEST_TOO_LARGE';
    public const TOO_MANY = 'NPR_ATTACHMENT_TOO_MANY';
    public const TYPE_NOT_ALLOWED = 'NPR_ATTACHMENT_TYPE_NOT_ALLOWED';
    public const EMPTY = 'NPR_ATTACHMENT_EMPTY';
    public const NO_FILE = 'NPR_ATTACHMENT_NO_FILE';

    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
