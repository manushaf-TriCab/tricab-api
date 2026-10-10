<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentFile;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentInvalid;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentNotFound;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/**
 * Files on invoices (T6.5): a document is uploaded unattached (app `AP`) with its SHA-256 kept beside it (open question
 * A2), put on the invoice when the invoice is created (record type `invoice`), served back through the record guard, and
 * removed with the invoice. The scan keeps its attachments the same way ({@see keep()} then {@see attach()}).
 *
 * The file ceiling is the store's `FinanceAttachmentRules::MAX_BYTES` (32 MB); a file is read whole into memory.
 */
final class ApAttachmentService
{
    public const RECORD_TYPE = 'invoice';
    public const NO_FILE = 'No file provided';
    public const NO_DOCUMENT = 'This invoice has no document';
    public const MISSING = 'The file is missing';
    public const UNKNOWN_UPLOAD = 'The upload was not found, or it is already on an invoice';

    /** The types an upload may be (`.pdf .png .jpg .jpeg .tif .tiff`, checked on the name only), and the type each is stored as. */
    public const UPLOAD_TYPES = [
        '.pdf' => 'application/pdf',
        '.png' => 'image/png',
        '.jpg' => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.tif' => 'image/tiff',
        '.tiff' => 'image/tiff',
    ];

    /** What a browser may show in place: PDF, PNG and JPEG. A TIFF is always a download. */
    private const INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg'];

    public function __construct(
        private readonly FinanceAttachmentService $files,
        private readonly FinanceAttachmentStore $store,
        private readonly ApInvoiceGuard $guard,
    ) {
    }

    /**
     * `POST /uploads`: keeps the file unattached and answers its id and the name the sender gave it.
     *
     * @return array{upload_id: string, name: string}
     */
    public function upload(ApActor $actor, ?string $fileName, string $bytes): array
    {
        if ($fileName === null || trim($fileName) === '') {
            throw new ApInvalid(self::NO_FILE);
        }
        $type = self::UPLOAD_TYPES[ApDownloadName::suffix($fileName)] ?? null;
        if ($type === null) {
            throw new ApInvalid('Unsupported file type: ' . $fileName);
        }
        $file = $this->keep($fileName, $type, $bytes, $actor->userId);

        return ['upload_id' => $file->id, 'name' => $file->originalName];
    }

    /**
     * Stores bytes against the app with no invoice yet (the scan's way in, and `upload`'s). The SHA-256 is on the returned row.
     */
    public function keep(string $fileName, string $contentType, string $bytes, ?int $uploaderUserId): FinanceAttachment
    {
        try {
            return $this->files->upload(Module::APP_AP, $fileName, $contentType, $bytes, $uploaderUserId);
        } catch (FinanceAttachmentInvalid $invalid) {
            throw new ApInvalid($invalid->getMessage());
        }
    }

    /**
     * The upload a capture names: it must exist for Tri-AP, be unattached and have been made by this person.
     */
    public function claim(ApActor $actor, string $uploadId): FinanceAttachment
    {
        $found = $this->store->find(Module::APP_AP, $uploadId);
        if ($found === null || $found->isAttached() || $found->uploadedByUserId !== $actor->userId) {
            throw new ApInvalid(self::UNKNOWN_UPLOAD);
        }

        return $found;
    }

    /** Takes back a file {@see keep()} stored that never went on an invoice (the scan's non-invoices and failures). May throw. */
    public function discard(string $fileId): void
    {
        $this->files->remove(Module::APP_AP, $fileId);
    }

    /** Puts an uploaded file on its invoice. Call it in the transaction that inserted the invoice. */
    public function attach(string $fileId, string $invoiceId, ?int $uploaderUserId): void
    {
        try {
            $this->files->attach(Module::APP_AP, $fileId, self::RECORD_TYPE, $invoiceId, $uploaderUserId);
        } catch (FinanceAttachmentNotFound) {
            throw new ApInvalid(self::UNKNOWN_UPLOAD);
        }
    }

    /**
     * `GET /invoices/{id}/file`: the invoice's document. The caller has found the invoice and applied the mailbox scope.
     *
     * @throws ApNotFound `This invoice has no document` when there is none, `The file is missing` when the store lost the bytes
     */
    public function file(ApActor $actor, ApInvoice $invoice): ApAttachmentFile
    {
        if ($invoice->fileId === null) {
            throw new ApNotFound(self::NO_DOCUMENT);
        }
        $file = $this->read($actor->userId, $invoice->fileId) ?? throw new ApNotFound(self::MISSING);

        return new ApAttachmentFile(
            $file->bytes,
            $file->attachment->contentType,
            self::headerName($invoice->attachment, $file->attachment->downloadName()),
            in_array($file->attachment->contentType, self::INLINE_TYPES, true),
        );
    }

    /** One stored file through the guard, or null when the store has no such file or has lost its bytes. */
    public function read(?int $viewerUserId, string $fileId): ?FinanceAttachmentFile
    {
        try {
            return $this->files->download(Module::APP_AP, $fileId, $viewerUserId, $this->guard);
        } catch (FinanceAttachmentNotFound) {
            return null;
        }
    }

    /**
     * Takes an invoice's files off the store after the invoice is gone: the file of the invoice and any other file kept against it.
     * May throw; the caller reports what is left behind.
     */
    public function removeInvoiceFiles(string $invoiceId, ?string $fileId): void
    {
        $this->files->removeRecordFiles(Module::APP_AP, self::RECORD_TYPE, $invoiceId);
        if ($fileId !== null) {
            $this->files->remove(Module::APP_AP, $fileId);
        }
    }

    /**
     * A name for a `Content-Disposition` header: the last path segment of the invoice's own `attachment` value, with anything a
     * header or a file system dislikes replaced; the file's own name when the invoice has none. Never a path.
     */
    public static function headerName(string $name, string $fallback): string
    {
        $parts = preg_split('~[\\\\/]~', $name) ?: [];
        $last = trim((string) end($parts));
        $last = preg_replace('/[\x00-\x1f\x7f"<>:*?|]/', '_', $last) ?? '';

        return $last === '' || $last === '.' || $last === '..' ? $fallback : $last;
    }
}
