<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;

/**
 * Evidence and credit-note bytes. The type comes from the file itself.
 * A browser Content-Type is only a mismatch check, never the source of truth.
 */
final class CustomerContactReportFilePolicy
{
    public const MAX_BYTES = 15728640;

    public const MAX_EVIDENCE = 20;

    /** @var array<string, string> */
    private const EVIDENCE = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'pdf' => 'application/pdf',
    ];

    /** @var array<string, string> */
    private const CREDIT_NOTE = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly string $purpose,
    ) {
    }

    public static function evidence(): self
    {
        return new self('evidence');
    }

    public static function creditNote(): self
    {
        return new self('credit_note');
    }

    /**
     * @return array{fileName: string, contentType: string, size: int}
     */
    public function inspect(string $originalName, string $bytes, ?string $clientMediaType): array
    {
        $size = strlen($bytes);
        if ($size < 1) {
            throw CustomerContactReportFileFailure::invalid('The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw CustomerContactReportFileFailure::tooLarge();
        }

        $fileName = self::displayName($originalName);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = $this->purpose === 'credit_note' ? self::CREDIT_NOTE : self::EVIDENCE;
        if (!isset($allowed[$extension])) {
            throw CustomerContactReportFileFailure::invalid(
                $this->purpose === 'credit_note'
                    ? 'A credit note must be a JPEG, PNG, or PDF.'
                    : 'Only images and PDFs are accepted.',
            );
        }

        $sniffed = self::sniff($bytes);
        if ($sniffed === null || $sniffed !== $allowed[$extension]) {
            throw CustomerContactReportFileFailure::invalid('The file contents do not match its extension.');
        }
        if (!self::clientAgrees($clientMediaType, $sniffed)) {
            throw CustomerContactReportFileFailure::invalid('The file type does not match its contents.');
        }

        return [
            'fileName' => $fileName,
            'contentType' => $sniffed,
            'size' => $size,
        ];
    }

    public static function displayName(string $originalName): string
    {
        $base = str_replace('\\', '/', $originalName);
        $base = basename($base);
        $cleaned = preg_replace('/[^A-Za-z0-9._ -]/', '', $base);
        $name = trim(is_string($cleaned) ? $cleaned : '');
        $name = trim($name, '.');
        if ($name === '') {
            return 'attachment';
        }
        if (strlen($name) > 255) {
            $name = substr($name, -255);
        }

        return $name;
    }

    private static function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx'], true)) {
                return 'image/heic';
            }
            if (in_array($brand, ['heif', 'mif1', 'msf1'], true)) {
                return 'image/heif';
            }
        }

        return null;
    }

    private static function clientAgrees(?string $clientMediaType, string $sniffed): bool
    {
        if ($clientMediaType === null) {
            return true;
        }
        $declared = strtolower(trim(explode(';', $clientMediaType)[0]));
        if ($declared === '') {
            return true;
        }
        if ($declared === 'image/jpg' || $declared === 'image/pjpeg') {
            $declared = 'image/jpeg';
        }
        if ($declared === 'image/heic-sequence') {
            $declared = 'image/heic';
        }

        return $declared === $sniffed;
    }
}
