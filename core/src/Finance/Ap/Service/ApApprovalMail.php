<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApInvoice;

/**
 * The two approval mails as the source wrote them (`app.py` 2634-2650 and 2490-2512, T6.11 P6/P7): the request to the Approvers and
 * the outcome to the Preparer. Every inserted value is HTML-escaped (`& < > " '`), because names, notes and vendors are typed or
 * read from a scanned email. The link is the page's own address on the site origin (decision A8).
 */
final class ApApprovalMail
{
    public const NO_COMMENT = 'No comment was provided.';
    public const ANONYMOUS_APPROVER = 'An Approver';

    private const CELL = "<td style='padding:6px;border-bottom:1px solid #ddd'>";
    private const CELL_RIGHT = "<td style='padding:6px;border-bottom:1px solid #ddd;text-align:right'>";

    private function __construct()
    {
    }

    /** The page for one batch: `<site origin>/finance/ap/approvals/<id>`. */
    public static function url(string $origin, string $batchId): string
    {
        return rtrim($origin, '/') . '/finance/ap/approvals/' . $batchId;
    }

    public static function requestSubject(int $count): string
    {
        return 'AP approval request: ' . $count . ' invoice(s)';
    }

    public static function outcomeSubject(string $status, int $count): string
    {
        return 'AP approval ' . strtolower($status) . ': ' . $count . ' invoice(s)';
    }

    /**
     * @param list<ApInvoice> $invoices in the order they were sent
     */
    public static function request(string $preparer, string $note, array $invoices, string $url): string
    {
        return '<p>' . self::escape($preparer) . ' submitted ' . count($invoices) . ' AP invoice(s) for approval.</p>'
            . '<p>' . self::escape($note) . '</p>'
            . self::table($invoices)
            . "<p><a href='" . self::escape($url) . "'>Review this approval request in Invoice Flow</a></p>"
            . '<p>Any active Approver may complete this request. The decision and approver identity will be recorded.</p>';
    }

    /**
     * @param list<ApInvoice> $invoices
     */
    public static function outcome(string $status, string $approver, string $comment, array $invoices, string $url): string
    {
        return '<p>Your AP approval request has been <strong>' . self::escape(strtolower($status)) . '</strong>.</p>'
            . '<p>Decision by: ' . self::escape($approver !== '' ? $approver : self::ANONYMOUS_APPROVER) . '</p>'
            . '<p>Comment: ' . self::escape($comment !== '' ? $comment : self::NO_COMMENT) . '</p>'
            . self::table($invoices)
            . "<p><a href='" . self::escape($url) . "'>View the completed approval request in Invoice Flow</a></p>";
    }

    /**
     * @param list<ApInvoice> $invoices
     */
    private static function table(array $invoices): string
    {
        $rows = '';
        foreach ($invoices as $invoice) {
            $rows .= '<tr>'
                . self::CELL . self::escape($invoice->vendor) . '</td>'
                . self::CELL . self::escape($invoice->invoiceNo) . '</td>'
                . self::CELL_RIGHT . self::escape($invoice->currency !== '' ? $invoice->currency : 'AUD') . ' '
                . number_format($invoice->totalAmount ?? 0.0, 2, '.', ',') . '</td>'
                . '</tr>';
        }

        return "<table style='border-collapse:collapse;width:100%'><thead><tr><th align='left'>Vendor</th><th align='left'>Invoice</th><th align='right'>Total</th></tr></thead>"
            . '<tbody>' . $rows . '</tbody></table>';
    }

    /** Python's `html.escape(text, quote=True)`. */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], $text);
    }
}
