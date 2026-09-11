<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Email;

use TriCab\Core\Expense\Domain\Entity\ExpenseBankFeedEntry;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;

/**
 * TriExpense transactional email bodies.
 *
 * Plain inline-styled HTML tables: Outlook is the primary client on the Tricab
 * fleet and it discards <style> blocks and most flex/grid layout.
 */
final class ExpenseEmailTemplates
{
    private const BRAND_NAVY = '#0b2545';

    private const BRAND_ACCENT = '#1f7ae0';

    private const BORDER = '#e2e8f0';

    public function __construct(
        private readonly ?string $appBaseUrl = null,
    ) {
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function submitted(ExpenseReceipt $receipt, string $submitMode): array
    {
        return [
            'subject' => sprintf(
                'TriExpense: receipt submitted for approval — %s %s',
                $receipt->vendorName(),
                $this->money($receipt),
            ),
            'html' => $this->document(
                'Receipt submitted for approval',
                'This receipt is now pending manager approval.',
                $this->receiptRows($receipt) + ['Submit mode' => $submitMode],
                $receipt,
            ),
        ];
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function approved(ExpenseReceipt $receipt): array
    {
        return [
            'subject' => sprintf(
                'TriExpense: receipt approved — %s %s',
                $receipt->vendorName(),
                $this->money($receipt),
            ),
            'html' => $this->document(
                'Receipt approved',
                'Your manager approved this receipt. No further action is needed.',
                $this->receiptRows($receipt),
                $receipt,
            ),
        ];
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function rejected(ExpenseReceipt $receipt, string $reason): array
    {
        return [
            'subject' => sprintf(
                'TriExpense: receipt rejected — %s %s',
                $receipt->vendorName(),
                $this->money($receipt),
            ),
            'html' => $this->document(
                'Receipt rejected',
                'Your manager rejected this receipt. Correct it and resubmit.',
                $this->receiptRows($receipt) + ['Reason' => $reason !== '' ? $reason : '—'],
                $receipt,
            ),
        ];
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function sentBack(ExpenseReceipt $receipt, string $note): array
    {
        return [
            'subject' => sprintf(
                'TriExpense: receipt sent back by Finance — %s %s',
                $receipt->vendorName(),
                $this->money($receipt),
            ),
            'html' => $this->document(
                'Receipt sent back by Finance',
                'Finance returned this receipt to pending for another look.',
                $this->receiptRows($receipt) + ['Finance note' => $note !== '' ? $note : '—'],
                $receipt,
            ),
        ];
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function chaseBankTransaction(ExpenseBankFeedEntry $entry): array
    {
        return [
            'subject' => sprintf(
                'TriExpense: receipt required for card transaction — %s %s',
                $entry->merchantName(),
                $entry->amount()->amount() . ' ' . $entry->currency()->toString(),
            ),
            'html' => $this->document(
                'Receipt required for a card transaction',
                'Finance could not match this card transaction to a receipt. Please upload one.',
                [
                    'Transaction' => $entry->publicId()->toString(),
                    'Merchant' => $entry->merchantName(),
                    'Transaction date' => $entry->transactionDate()?->format('d M Y') ?? '—',
                    'Amount' => $entry->amount()->amount() . ' ' . $entry->currency()->toString(),
                    'Cardholder' => $entry->cardholderEmail(),
                ],
                null,
            ),
        ];
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function missingReceiptsDigest(string $summary): array
    {
        return [
            'subject' => 'TriExpense: card transactions missing receipts',
            'html' => $this->document(
                'Card transactions missing receipts',
                'The transactions below have no matching receipt in TriExpense.',
                [],
                null,
                $summary,
            ),
        ];
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function managerPendingDigest(string $summary): array
    {
        return [
            'subject' => 'TriExpense: expense receipts awaiting your approval',
            'html' => $this->document(
                'Receipts awaiting your approval',
                'The receipts below are still pending your approval.',
                [],
                null,
                $summary,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function receiptRows(ExpenseReceipt $receipt): array
    {
        return [
            'Receipt reference' => $receipt->publicId()->toString(),
            'Vendor' => $receipt->vendorName(),
            'Receipt date' => $receipt->receiptDate()?->format('d M Y') ?? '—',
            'Amount' => $this->money($receipt),
            'GST' => $receipt->gstAmount()->amount() . ' (' . $receipt->gstType() . ')',
            'Category' => $receipt->category()->rawValue(),
            'Cost centre' => $receipt->costCentre() !== '' ? $receipt->costCentre() : '—',
            'Status' => $receipt->status()->toString(),
        ];
    }

    private function money(ExpenseReceipt $receipt): string
    {
        return $receipt->totalAmount()->amount() . ' ' . $receipt->currency()->toString();
    }

    /**
     * @param array<string, string> $rows
     */
    private function document(
        string $heading,
        string $intro,
        array $rows,
        ?ExpenseReceipt $receipt,
        ?string $preformatted = null,
    ): string {
        $body = '';
        if ($rows !== []) {
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
                . 'style="border-collapse:collapse;border:1px solid ' . self::BORDER . ';border-radius:6px;">';
            foreach ($rows as $label => $value) {
                $body .= '<tr>'
                    . '<td style="padding:10px 14px;border-bottom:1px solid ' . self::BORDER . ';'
                    . 'font:13px Segoe UI,Arial,sans-serif;color:#6b7280;white-space:nowrap;">'
                    . $this->escape($label)
                    . '</td>'
                    . '<td style="padding:10px 14px;border-bottom:1px solid ' . self::BORDER . ';'
                    . 'font:600 13px Segoe UI,Arial,sans-serif;color:#111827;">'
                    . $this->escape($value)
                    . '</td>'
                    . '</tr>';
            }
            $body .= '</table>';
        }

        if ($preformatted !== null && trim($preformatted) !== '') {
            $body .= '<pre style="margin:16px 0 0;padding:14px;background:#f8fafc;border:1px solid '
                . self::BORDER . ';border-radius:6px;font:12px/1.6 Consolas,Menlo,monospace;'
                . 'color:#111827;white-space:pre-wrap;">'
                . $this->escape($preformatted)
                . '</pre>';
        }

        $link = $this->receiptUrl($receipt);
        if ($link !== null) {
            $body .= '<p style="margin:24px 0 0;">'
                . '<a href="' . $this->escape($link) . '" '
                . 'style="display:inline-block;padding:11px 22px;background:' . self::BRAND_ACCENT . ';'
                . 'color:#ffffff;font:600 14px Segoe UI,Arial,sans-serif;text-decoration:none;border-radius:6px;">'
                . 'Open in TriExpense</a></p>';
        }

        return '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:24px;background:#f1f5f9;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
            . 'style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;">'
            . '<tr><td style="padding:20px 28px;background:' . self::BRAND_NAVY . ';">'
            . '<span style="font:700 18px Segoe UI,Arial,sans-serif;color:#ffffff;letter-spacing:0.3px;">TriCab</span>'
            . '<span style="font:400 18px Segoe UI,Arial,sans-serif;color:#9ec5f5;"> TriExpense</span>'
            . '</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<h1 style="margin:0 0 8px;font:600 20px Segoe UI,Arial,sans-serif;color:#111827;">'
            . $this->escape($heading) . '</h1>'
            . '<p style="margin:0 0 20px;font:14px/1.6 Segoe UI,Arial,sans-serif;color:#4b5563;">'
            . $this->escape($intro) . '</p>'
            . $body
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px 24px;border-top:1px solid ' . self::BORDER . ';'
            . 'font:12px/1.6 Segoe UI,Arial,sans-serif;color:#6b7280;">'
            . 'Automated message from TriExpense. Reply to this address only if you need help from Accounts Payable.'
            . '</td></tr>'
            . '</table></body></html>';
    }

    private function receiptUrl(?ExpenseReceipt $receipt): ?string
    {
        $base = $this->appBaseUrl !== null ? rtrim(trim($this->appBaseUrl), '/') : '';
        if ($base === '' || $receipt === null) {
            return null;
        }

        return $base . '/tri-expense/receipts/' . rawurlencode($receipt->publicId()->toString());
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
