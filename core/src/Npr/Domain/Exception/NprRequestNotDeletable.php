<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain\Exception;

use RuntimeException;

/** Only a draft that was never submitted can be deleted. Maps to 409 `NPR_NOT_DELETABLE`. */
final class NprRequestNotDeletable extends RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(sprintf("A request that is '%s' or has been submitted cannot be deleted; only a never-submitted draft can.", $status));
    }
}
