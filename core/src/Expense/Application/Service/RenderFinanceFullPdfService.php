<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Contract\ExpensePdfRenderer;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;

final class RenderFinanceFullPdfService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpensePdfRenderer $pdf,
        private readonly ExpenseAttachmentStore $attachments,
    ) {
    }

    public function execute(ExpenseActor $actor, ?string $country = null): string
    {
        $this->access->requireFinance($actor);
        unset($country);

        $categories = ExpenseCatalog::categories();
        $sections = '';
        foreach ($this->receipts->listAll(false) as $receipt) {
            if ($receipt->status()->isDraft()) {
                continue;
            }
            $code = $receipt->category()->rawValue();
            $catName = htmlspecialchars(
                $categories[$code] ?? $receipt->category()->otherCategory() ?? $code,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            $attachmentHtml = $this->attachmentHtml($receipt->attachment());

            $sections .= '<section style="margin-bottom:18px;page-break-inside:avoid">'
                . '<h2 style="font-size:13px;margin:0 0 6px">'
                . htmlspecialchars($receipt->vendorName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' — '
                . htmlspecialchars($receipt->publicId()->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</h2>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:8px">'
                . '<tr><th style="text-align:left;border:1px solid #ccc;padding:3px 5px;background:#f3f3f3">Date</th>'
                . '<td style="border:1px solid #ccc;padding:3px 5px">'
                . htmlspecialchars((string) $receipt->receiptDate()?->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td>'
                . '<th style="text-align:left;border:1px solid #ccc;padding:3px 5px;background:#f3f3f3">Amount</th>'
                . '<td style="border:1px solid #ccc;padding:3px 5px">'
                . htmlspecialchars($receipt->totalAmount()->amount() . ' ' . $receipt->currency()->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td></tr>'
                . '<tr><th style="text-align:left;border:1px solid #ccc;padding:3px 5px;background:#f3f3f3">GST</th>'
                . '<td style="border:1px solid #ccc;padding:3px 5px">'
                . htmlspecialchars($receipt->gstAmount()->amount(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td>'
                . '<th style="text-align:left;border:1px solid #ccc;padding:3px 5px;background:#f3f3f3">Category</th>'
                . '<td style="border:1px solid #ccc;padding:3px 5px">' . $catName . '</td></tr>'
                . '<tr><th style="text-align:left;border:1px solid #ccc;padding:3px 5px;background:#f3f3f3">Status</th>'
                . '<td style="border:1px solid #ccc;padding:3px 5px" colspan="3">'
                . htmlspecialchars($receipt->status()->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td></tr>'
                . '</table>'
                . $attachmentHtml
                . '</section>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#222}
h1{font-size:16px;margin:0 0 12px}
.placeholder{color:#666;font-style:italic;margin:6px 0}
.image-note{margin:6px 0;padding:8px;border:1px dashed #aaa;background:#fafafa}
</style></head><body>
<h1>TriExpense Finance Full Report</h1>
{$sections}
</body></html>
HTML;

        return $this->pdf->renderHtml($html);
    }

    private function attachmentHtml(?ExpenseAttachmentRef $ref): string
    {
        if ($ref === null) {
            return '<p class="placeholder">No attachment</p>';
        }

        // WordPress / legacy dual-read: never emit raw URLs.
        if (
            $ref->storageSource() === ExpenseAttachmentRef::SOURCE_WORDPRESS
            || $ref->dualReadClass() === 'legacy_wordpress'
            || $ref->dualReadClass() === 'unknown'
        ) {
            return '<p class="placeholder">Legacy WordPress attachment (not migrated)</p>';
        }

        if ($ref->isAzureBlobPath()) {
            $path = ltrim($ref->reference(), '/');
            $fileName = htmlspecialchars(
                (string) ($ref->fileName() ?? $path),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            if ($this->attachments->objectExists($path)) {
                return '<div class="image-note">[Azure image: ' . $fileName . ']</div>';
            }

            return '<p class="placeholder">Azure attachment missing: ' . $fileName . '</p>';
        }

        // legacy_azure_url — do not embed raw URL
        if ($ref->storageSource() === ExpenseAttachmentRef::SOURCE_AZURE_BLOB) {
            $fileName = htmlspecialchars(
                (string) ($ref->fileName() ?? 'attachment'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );

            return '<div class="image-note">[Azure attachment available: ' . $fileName . ']</div>';
        }

        return '<p class="placeholder">Legacy WordPress attachment (not migrated)</p>';
    }
}
