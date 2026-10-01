<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;

/**
 * A signature pad data URL. The PNG magic bytes are required.
 * SVG and HTML are not a signature.
 */
final class CustomerContactReportSignatureImage
{
    private const PREFIX = 'data:image/png;base64,';

    private const MAX_TEXT = 1400000;

    private const MAX_BYTES = 1048576;

    private function __construct(
        private readonly string $bytes,
    ) {
    }

    public static function fromDataUrl(string $dataUrl): self
    {
        if (!str_starts_with($dataUrl, self::PREFIX)) {
            throw CustomerContactReportFileFailure::signatureInvalid('The signature must be a PNG data URL.');
        }
        $encoded = substr($dataUrl, strlen(self::PREFIX));
        if ($encoded === '' || strlen($encoded) > self::MAX_TEXT) {
            throw CustomerContactReportFileFailure::signatureInvalid('The signature could not be read.');
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw CustomerContactReportFileFailure::signatureInvalid('The signature could not be read.');
        }
        if (!str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            throw CustomerContactReportFileFailure::signatureInvalid('The signature is not a PNG.');
        }

        return new self($raw);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}
