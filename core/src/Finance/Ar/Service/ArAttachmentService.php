<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use Throwable;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentInvalid;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentNotFound;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;

/**
 * Files on records (T5.5): keeping a file from a message through the Phase 1 file store (app `AR`, record type
 * `email_record`, so a permanent delete removes them) and serving one back through the record guard.
 *
 * The file ceiling is the store's `FinanceAttachmentRules::MAX_BYTES` on the way in; a file response is read
 * whole into memory, so it is the ceiling on the way out too.
 */
final class ArAttachmentService
{
    public const NOT_FOUND = 'Attachment not found';
    public const MISSING = 'Attachment file is missing';

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly ArRecordStore $records,
        private readonly FinanceAttachmentService $files,
        private readonly ArRecordGuard $guard,
        private readonly ArTime $time,
        ?callable $newId = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    /**
     * Stores the bytes against the record and adds the attachment row. A name used twice is two files. If the row
     * cannot be written the file is taken off again, so nothing is left without an owner.
     *
     * @return string the attachment row's id
     */
    public function keep(string $recordId, string $fileName, string $contentType, string $bytes, string $extractionStatus = ArKeptAttachment::PENDING): string
    {
        if (!in_array($extractionStatus, ArKeptAttachment::STATUSES, true)) {
            throw new ArInvalid('Unknown extraction status');
        }
        if (!$this->records->exists($recordId)) {
            throw new ArNotFound(ArRecordService::NOT_FOUND);
        }
        try {
            $file = $this->files->upload(Module::APP_AR, $fileName, $contentType, $bytes, null);
            $file = $this->files->attach(Module::APP_AR, $file->id, ArRecordService::ATTACHMENT_RECORD_TYPE, $recordId, null);
        } catch (FinanceAttachmentInvalid $invalid) {
            throw new ArInvalid($invalid->getMessage());
        }
        $id = ($this->newId)();
        try {
            $this->records->addAttachment(new ArKeptAttachment(
                $id, $recordId, $file->id, $file->originalName, $file->contentType, $file->byteSize, $extractionStatus, $this->time->now(),
            ));
        } catch (Throwable $failure) {
            $this->files->remove(Module::APP_AR, $file->id);
            throw $failure;
        }

        return $id;
    }

    /**
     * One kept file, if the attachment row belongs to the record in the URL and the file store still has the bytes.
     */
    public function file(ArActor $actor, string $recordId, string $attachmentId): ArAttachmentFile
    {
        $fileId = $this->records->attachmentFileId($recordId, $attachmentId) ?? throw new ArNotFound(self::NOT_FOUND);
        try {
            $file = $this->files->download(Module::APP_AR, $fileId, $actor->userId, $this->guard);
        } catch (FinanceAttachmentNotFound) {
            throw new ArNotFound(self::MISSING);
        }

        return new ArAttachmentFile($file->bytes, $file->attachment->contentType, $file->attachment->downloadName());
    }
}
