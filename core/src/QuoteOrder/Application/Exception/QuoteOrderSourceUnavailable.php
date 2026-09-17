<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class QuoteOrderSourceUnavailable extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Quotes & Orders legacy read is not enabled.');
    }

    public static function connectionMissing(): self
    {
        return new self('Quotes & Orders legacy data source is unavailable.');
    }

    public static function queryFailed(): self
    {
        return new self('Quotes & Orders legacy data source is temporarily unavailable.');
    }
}
