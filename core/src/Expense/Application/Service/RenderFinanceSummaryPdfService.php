<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\ExpenseCatalog;
use TriCab\Core\Expense\Contract\ExpensePdfRenderer;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;

final class RenderFinanceSummaryPdfService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpensePdfRenderer $pdf,
    ) {
    }

    public function execute(ExpenseActor $actor, ?string $country = null): string
    {
        $this->access->requireFinance($actor);
        unset($country);

        $categories = ExpenseCatalog::categories();
        $rows = '';
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
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($receipt->publicId()->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $receipt->receiptDate()?->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($receipt->vendorName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . $catName . '</td>'
                . '<td>' . htmlspecialchars($receipt->totalAmount()->amount(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($receipt->gstAmount()->amount(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($receipt->currency()->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($receipt->status()->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#222}
h1{font-size:16px;margin:0 0 12px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
th{background:#f3f3f3}
</style></head><body>
<h1>TriExpense Finance Summary</h1>
<table>
<thead><tr>
<th>ID</th><th>Date</th><th>Vendor</th><th>Category</th><th>Amount</th><th>GST</th><th>Currency</th><th>Status</th>
</tr></thead>
<tbody>{$rows}</tbody>
</table>
</body></html>
HTML;

        return $this->pdf->renderHtml($html);
    }
}
