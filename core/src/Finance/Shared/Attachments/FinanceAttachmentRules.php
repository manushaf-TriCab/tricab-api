<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Module;

/**
 * The limits and shapes of the finance file store. The size ceiling is the largest
 * request any of the five apps takes today (Tri-AP's 32 MB); an app that wants less,
 * or only some file types, checks that itself before it stores.
 */
final class FinanceAttachmentRules
{
    public const MAX_BYTES = 33554432;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_CONTENT_TYPE_LENGTH = 150;

    public const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    /** Lower-case words and underscores: `credit_note`, `email_record`. */
    public const RECORD_TYPE_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /** Whatever an app's own ids look like (numbers, 32-hex, `CN-2026-0001`). */
    public const RECORD_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/';

    private function __construct()
    {
    }

    public static function checkApplication(string $application): void
    {
        if (!isset(Module::APPS[$application])) {
            throw new FinanceAttachmentInvalid('Unknown finance app.');
        }
    }

    public static function checkRecord(string $recordType, string $recordId): void
    {
        if (preg_match(self::RECORD_TYPE_PATTERN, $recordType) !== 1) {
            throw new FinanceAttachmentInvalid('The record type must be lower-case words joined by underscores.');
        }
        if (preg_match(self::RECORD_ID_PATTERN, $recordId) !== 1) {
            throw new FinanceAttachmentInvalid('The record id is not a usable id.');
        }
    }

    /**
     * The name the sender gave, kept as data: trimmed, never empty, no control
     * characters. A `/` or `..` in it is allowed here because it is never used as a
     * path; see {@see FinanceAttachment::downloadName()} for the form safe in a header.
     */
    public static function cleanName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new FinanceAttachmentInvalid(sprintf('A file name is required, up to %d characters, with no control characters.', self::MAX_NAME_LENGTH));
        }

        return $name;
    }

    public static function cleanContentType(string $contentType): string
    {
        $contentType = trim($contentType);
        if ($contentType === '') {
            return self::DEFAULT_CONTENT_TYPE;
        }
        if (strlen($contentType) > self::MAX_CONTENT_TYPE_LENGTH || preg_match('/^[\w!#$&^.+-]+\/[\w!#$&^.+-]+(\s*;[^\x00-\x1f\x7f]*)?$/', $contentType) !== 1) {
            throw new FinanceAttachmentInvalid('The content type is not valid.');
        }

        return $contentType;
    }

    public static function checkBytes(string $bytes): void
    {
        if ($bytes === '') {
            throw new FinanceAttachmentInvalid('The file is empty.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new FinanceAttachmentInvalid(sprintf('The file is larger than %d MB.', intdiv(self::MAX_BYTES, 1048576)));
        }
    }

    /**
     * Where the bytes go: the app, the month and the file's own id. Built only from
     * these three, never from the file's name, so two files with the same name can
     * never meet and a name cannot steer a path.
     */
    public static function blobPath(string $application, string $id, DateTimeImmutable $at): string
    {
        return strtolower($application) . '/' . $at->setTimezone(new DateTimeZone('UTC'))->format('Y/m') . '/' . $id;
    }
}
