<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Attachment;

use TriCab\Core\Npr\Domain\Exception\NprAttachmentRejected;

/**
 * The attachment limits of npr-portal docs/ARCHITECTURE.md (proposed, pending
 * TSD/IT approval): 10 MiB a file, 30 MiB and 20 files a request; PDF, DOCX,
 * XLSX, CSV, PPTX, JPEG and PNG. The extension must be allowed AND the content
 * must sniff as that type: the client's declared type is never trusted.
 */
final class NprAttachmentRules
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_REQUEST_BYTES = 30 * 1024 * 1024;
    public const MAX_FILES = 20;

    private const OOXML = [
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word/document.xml'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xl/workbook.xml'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ppt/presentation.xml'],
    ];

    /** @var list<string> */
    public const EXTENSIONS = ['pdf', 'docx', 'xlsx', 'csv', 'pptx', 'jpg', 'jpeg', 'png'];

    /**
     * The canonical content type of the bytes for this extension.
     *
     * @throws NprAttachmentRejected
     */
    public static function check(string $filename, string $bytes): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new NprAttachmentRejected(
                NprAttachmentRejected::TYPE_NOT_ALLOWED,
                sprintf("File extension '.%s' is not permitted. Allowed: %s.", $extension, implode(', ', self::EXTENSIONS)),
            );
        }
        $size = strlen($bytes);
        if ($size === 0) {
            throw new NprAttachmentRejected(NprAttachmentRejected::EMPTY, 'The file is empty.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new NprAttachmentRejected(NprAttachmentRejected::TOO_LARGE, 'File exceeds the 10 MiB limit.', 413);
        }

        $mime = self::sniff($extension, $bytes);
        if ($mime === null) {
            throw new NprAttachmentRejected(
                NprAttachmentRejected::TYPE_NOT_ALLOWED,
                sprintf("The content of the file does not match its '.%s' extension.", $extension),
            );
        }

        return $mime;
    }

    private static function sniff(string $extension, string $bytes): ?string
    {
        $detected = function_exists('finfo_buffer') ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : false;
        $detected = is_string($detected) ? $detected : null;

        switch ($extension) {
            case 'pdf':
                return str_contains(substr($bytes, 0, 1024), '%PDF-') && ($detected === null || $detected === 'application/pdf')
                    ? 'application/pdf' : null;
            case 'png':
                return str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && ($detected === null || $detected === 'image/png')
                    ? 'image/png' : null;
            case 'jpg':
            case 'jpeg':
                return str_starts_with($bytes, "\xFF\xD8\xFF") && ($detected === null || $detected === 'image/jpeg')
                    ? 'image/jpeg' : null;
            case 'csv':
                // Text only: no NUL byte, and (when finfo can tell) a text type.
                return !str_contains($bytes, "\0") && ($detected === null || str_starts_with($detected, 'text/') || $detected === 'application/csv')
                    ? 'text/csv' : null;
            default:
                [$mime, $part] = self::OOXML[$extension];

                return str_starts_with($bytes, "PK\x03\x04") && self::zipHas($bytes, ['[Content_Types].xml', $part]) ? $mime : null;
        }
    }

    /** @param list<string> $entries */
    private static function zipHas(string $bytes, array $entries): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }
        $path = tempnam(sys_get_temp_dir(), 'npr');
        if ($path === false) {
            return false;
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return false;
            }
            $found = true;
            foreach ($entries as $entry) {
                $found = $found && $zip->locateName($entry) !== false;
            }
            $zip->close();

            return $found;
        } finally {
            @unlink($path);
        }
    }
}
