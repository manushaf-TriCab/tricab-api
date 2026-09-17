<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class QuoteWriteMisconfiguredException extends RuntimeException
{
    public static function missingCredentials(): self
    {
        return new self(
            'Quote legacy write is enabled but write credentials are incomplete. '
            . 'Configure QUOTES_ORDERS_LEGACY_WRITE_DSN/USER/PASSWORD (never reuse LEGACY_TC RO credentials silently).',
        );
    }

    public static function missingTableSuffix(): self
    {
        return new self(
            'Quote legacy write is enabled but QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX is not set. '
            . 'Use "_dev" for non-production or "" only after explicit production approval.',
        );
    }

    public static function invalidTableSuffix(string $suffix): self
    {
        return new self(
            sprintf(
                'Invalid QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX "%s". Allowed: "" or "_dev".',
                $suffix,
            ),
        );
    }

    public static function firstWriteRequiresDevSuffix(): self
    {
        return new self(
            'First non-production quote write requires QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX=_dev.',
        );
    }
}
