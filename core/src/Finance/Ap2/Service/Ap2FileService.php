<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentNotFound;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceRecordGuard;

/**
 * Tri-AP's files in `finance.attachments` (application key AP2): a mail's attachments are stored against the record
 * type `ap2_mail`, and an invoice's source document is one of them. The route's own permission is the record check
 * (everyone who may read mail or invoices sees every file), so the guard says yes; a file that is not a Tri-AP file
 * is still "not found".
 */
final class Ap2FileService implements FinanceRecordGuard
{
    public const RECORD_MAIL = 'ap2_mail';

    public function __construct(private readonly FinanceAttachmentService $files)
    {
    }

    /** Stores bytes, not yet on a record (put them on one with {@see attachToMail()} once the mail row exists). */
    public function store(string $name, string $contentType, string $bytes, ?int $uploaderUserId): FinanceAttachment
    {
        return $this->files->upload(Module::APP_AP2, $name, $contentType, $bytes, $uploaderUserId);
    }

    public function attachToMail(string $fileId, string $mailId, ?int $uploaderUserId): void
    {
        $this->files->attach(Module::APP_AP2, $fileId, self::RECORD_MAIL, $mailId, $uploaderUserId);
    }

    /**
     * @throws Ap2NotFound when there is no such file
     */
    public function read(string $fileId, ?int $viewerUserId): Ap2File
    {
        try {
            $file = $this->files->download(Module::APP_AP2, $fileId, $viewerUserId, $this);
        } catch (FinanceAttachmentNotFound) {
            throw new Ap2NotFound('File not found');
        }

        return new Ap2File($file->attachment->downloadName(), $file->attachment->contentType, $file->bytes, $file->attachment->sha256);
    }

    public function maySee(string $application, string $recordType, string $recordId): bool
    {
        return $application === Module::APP_AP2 && $recordType === self::RECORD_MAIL;
    }
}
