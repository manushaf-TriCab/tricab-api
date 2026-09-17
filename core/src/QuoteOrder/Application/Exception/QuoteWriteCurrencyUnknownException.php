<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use InvalidArgumentException;

final class QuoteWriteCurrencyUnknownException extends InvalidArgumentException
{
    public static function forCode(string $currencyCode): self
    {
        return new self(
            sprintf('Unknown quote currency "%s". Supported: AUD, NZD, USD.', $currencyCode),
        );
    }
}
