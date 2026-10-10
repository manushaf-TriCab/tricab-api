<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;

/**
 * `POST /invoices/download` (the 64th route, T6.5 F3): the documents of the ticked invoices, the file itself for one and a
 * zip for several, each named "vendor - invoice date - total - cost centre" ({@see ApDownloadName}) and made unique inside
 * the zip.
 *
 * - No ids, or not a list: 422 `Select the invoices to download`. More than 500 distinct ids: 422 `Download up to 500 invoices
 *   at a time`.
 * - Any id that is missing or outside the person's mailbox scope fails the WHOLE request with 404 `Not found` before any file
 *   is read. A binned invoice is not refused.
 * - An invoice with no document, or whose bytes are gone, is counted and skipped; none left is 404 `None of the selected
 *   invoices has a document to download`.
 * - The documents are read whole into memory, so their total is capped (the source had no cap, R26).
 */
final class ApInvoiceDownloadService
{
    public const MAX_INVOICES = 500;
    public const NO_IDS = 'Select the invoices to download';
    public const NO_DOCUMENTS = 'None of the selected invoices has a document to download';

    /** The most bytes of documents one download holds. */
    public const MAX_TOTAL_BYTES = 134217728;

    private const MIME_TYPES = [
        '.pdf' => 'application/pdf', '.png' => 'image/png', '.jpg' => 'image/jpeg', '.jpeg' => 'image/jpeg',
        '.tif' => 'image/tiff', '.tiff' => 'image/tiff', '.gif' => 'image/gif', '.bmp' => 'image/bmp',
        '.txt' => 'text/plain', '.csv' => 'text/csv', '.xml' => 'application/xml', '.json' => 'application/json',
        '.zip' => 'application/zip', '.doc' => 'application/msword',
        '.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        '.xls' => 'application/vnd.ms-excel',
        '.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly ApInvoiceStore $invoices,
        private readonly ApAttachmentService $attachments,
        private readonly ApTime $time,
        private readonly int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ) {
    }

    /**
     * @param mixed $ids the body's `ids`
     */
    public function download(ApActor $actor, mixed $ids): ApDownload
    {
        if (!is_array($ids) || $ids === []) {
            throw new ApInvalid(self::NO_IDS);
        }
        $distinct = [];
        foreach ($ids as $id) {
            $distinct[self::text($id)] = true;
        }
        $distinct = array_map('strval', array_keys($distinct));
        if (count($distinct) > self::MAX_INVOICES) {
            throw new ApInvalid('Download up to ' . self::MAX_INVOICES . ' invoices at a time');
        }

        $found = $this->invoices->findMany($distinct);
        $selected = [];
        foreach ($distinct as $id) {
            $invoice = $found[$id] ?? null;
            if ($invoice === null || !$actor->mailboxScope->allowsRecord($invoice->mailbox)) {
                throw new ApNotFound(ApInvoiceService::NOT_FOUND);
            }
            $selected[] = $invoice;
        }

        $files = [];
        $used = [];
        $missing = 0;
        $total = 0;
        foreach ($selected as $invoice) {
            $file = $invoice->fileId === null ? null : $this->attachments->read($actor->userId, $invoice->fileId);
            if ($file === null) {
                ++$missing;

                continue;
            }
            $total += strlen($file->bytes);
            if ($total > $this->maxTotalBytes) {
                throw new ApInvalid('The selected documents are too large to download at once; select fewer invoices');
            }
            $suffix = ApDownloadName::suffix($invoice->attachment);
            $name = ApDownloadName::forInvoice($invoice, $suffix);
            // Two invoices can share vendor, date, total and cost centre (a duplicate is exactly that), and a zip must not hold a name twice.
            $attempt = 2;
            while (isset($used[mb_strtolower($name)])) {
                $name = ApDownloadName::forInvoice($invoice, ' (' . $attempt . ')' . $suffix);
                ++$attempt;
            }
            $used[mb_strtolower($name)] = true;
            $files[] = [$name, self::mimeType($invoice), $file->bytes];
        }
        if ($files === []) {
            throw new ApNotFound(self::NO_DOCUMENTS);
        }
        if (count($files) === 1) {
            [$name, $mime, $bytes] = $files[0];

            return new ApDownload($bytes, $mime, $name, $missing);
        }
        $zip = new ApZipWriter($this->time->now()->getTimestamp());
        foreach ($files as [$name, , $bytes]) {
            $zip->add($name, $bytes);
        }

        return new ApDownload($zip->bytes(), 'application/zip', 'AP invoices ' . $this->time->today() . ' (' . count($files) . ').zip', $missing);
    }

    /** The type guessed from the invoice's own file name (`mimetypes.guess_type`), `application/octet-stream` when unknown. */
    private static function mimeType(ApInvoice $invoice): string
    {
        return self::MIME_TYPES[ApDownloadName::suffix($invoice->attachment)] ?? 'application/octet-stream';
    }

    /** An id as text (`str(row_id)`): numbers and booleans too, anything else cannot name an invoice. */
    private static function text(mixed $id): string
    {
        return match (true) {
            is_string($id) => $id,
            is_int($id), is_float($id) => (string) $id,
            is_bool($id) => $id ? 'True' : 'False',
            default => 'None',
        };
    }
}
