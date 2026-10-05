<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Sync;

/**
 * The best-effort render of the quote PDF (QOTPDF). A failure here never fails
 * the sync: the quote and its lines already exist in 4js.
 */
final class GatewayPdfResult
{
    private function __construct(public readonly ?string $url, public readonly ?string $error)
    {
    }

    public static function rendered(string $url): self
    {
        return new self($url, null);
    }

    public static function failed(string $error): self
    {
        return new self(null, $error);
    }
}
