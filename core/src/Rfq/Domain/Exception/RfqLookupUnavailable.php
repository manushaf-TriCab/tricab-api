<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;
use Throwable;

/** A header lookup's source is not configured or not answering. Maps to 503. */
final class RfqLookupUnavailable extends RuntimeException
{
    public function __construct(public readonly string $lookup, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('The %s lookup is not available right now.', $lookup), 0, $previous);
    }
}
