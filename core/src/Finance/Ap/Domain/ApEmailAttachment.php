<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_email_attachments`: a file of a scanned message that the scan looked at (the source's
 * `email_attachments` row). Only invoices keep their bytes (as the file `fileId`); the row of any other file is the record
 * that it was seen.
 *
 * - `invoiceId` is the invoice captured from the file; `duplicateOfInvoiceId` is the invoice an exact copy of the file
 *   already was, when the scan filed none (the AP Mailbox lists it as a duplicate of the email).
 * - `graphAttachmentId` is null for a file Graph gave no id; the pair (email, Graph id) is unique when there is one.
 */
final readonly class ApEmailAttachment
{
    /** The columns {@see \TriCab\Core\Finance\Ap\Contract\ApEmailStore::updateAttachment()} may write. */
    public const UPDATABLE = ['name', 'content_type', 'size', 'content_hash', 'file_id', 'is_invoice', 'invoice_id', 'duplicate_of_invoice_id'];

    public const TEXT_LIMITS = ['graph_attachment_id' => 400, 'name' => 255, 'content_type' => 150];

    public function __construct(
        public string $id,
        public string $emailRecordId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $graphAttachmentId = null,
        public string $name = '',
        public string $contentType = '',
        public ?int $size = null,
        public ?string $contentHash = null,
        public ?string $fileId = null,
        public bool $isInvoice = false,
        public ?string $invoiceId = null,
        public ?string $duplicateOfInvoiceId = null,
    ) {
    }

    /** A copy with some properties replaced, by property name. */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * A copy with some columns replaced, by column name (`invoice_id`).
     *
     * @param array<string, mixed> $columns
     */
    public function withColumns(array $columns): self
    {
        $changes = [];
        foreach ($columns as $column => $value) {
            $changes[lcfirst(str_replace('_', '', ucwords($column, '_')))] = $value;
        }

        return $this->with(...$changes);
    }
}
