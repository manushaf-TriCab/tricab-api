<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Domain\ArCreditNote;

/**
 * The two mails of the credit-note flow, as HTML. The source escaped three values and put the rest in as typed;
 * here every value is escaped (decision D46): a customer's name or a rep's can be anything.
 */
final class ArCreditNoteMail
{
    private function __construct()
    {
    }

    /** One line, no control characters, at most 500 characters: a subject carries values from outside. */
    public static function subject(string $text): string
    {
        $line = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text));

        return mb_substr($line, 0, 500);
    }

    public static function linkSubject(ArCreditNote $note): string
    {
        return self::subject('Credit Note Request — ' . $note->customerName);
    }

    public static function linkBody(ArCreditNote $note, string $formUrl, int $hours): string
    {
        $rep = self::e($note->salesRepName !== '' ? $note->salesRepName : 'there');
        $customer = self::e($note->customerName);
        $original = str_replace("\n", '<br/>', self::e(str_replace(["\r\n", "\r"], "\n", $note->originalRequest)));
        $url = self::e($formUrl);

        return '<div style="font-family: Arial, sans-serif; color: #1e293b; max-width: 600px; line-height: 1.5;">'
            . '<p>Hi ' . $rep . ',</p>'
            . '<p>A credit note request has been initiated for <strong>' . $customer . '</strong> and requires your details.</p>'
            . '<p>Please complete and submit the credit note form at the link below:</p>'
            . '<p style="margin: 24px 0;"><a href="' . $url . '" style="display:inline-block;padding:10px 20px;background:#0f172a;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:bold;">Complete Credit Note Form</a></p>'
            . '<p style="color:#64748b;font-size:13px;margin-top:24px;background:#f8fafc;padding:12px;border-left:3px solid #cbd5e1;"><strong>Original Enquiry Summary:</strong><br/>' . $original . '</p>'
            . '<p style="color:#94a3b8;font-size:12px;">This form link will expire in ' . $hours . ' hours. If the button does not work, copy this URL:<br/>' . $url . '</p>'
            . '</div>';
    }

    public static function submittedSubject(ArCreditNote $note): string
    {
        return self::subject('Credit note form submitted — ' . $note->customerName);
    }

    public static function submittedBody(ArCreditNote $note): string
    {
        $rep = $note->salesRepName !== '' ? $note->salesRepName : $note->salesRepEmail;

        return '<div style="font-family: Arial, sans-serif; color: #1e293b;">'
            . '<p><strong>Credit note form submitted</strong></p>'
            . '<ul>'
            . '<li><strong>Customer:</strong> ' . self::e($note->customerName) . '</li>'
            . '<li><strong>Submitted by:</strong> ' . self::e($rep) . '</li>'
            . '<li><strong>Invoice:</strong> ' . self::e($note->invoiceNumber) . '</li>'
            . '<li><strong>Credit amount:</strong> $' . self::e(number_format($note->creditAmount, 2, '.', ',')) . '</li>'
            . '<li><strong>Reason:</strong> ' . str_replace("\n", '<br/>', self::e($note->reason)) . '</li>'
            . '</ul>'
            . '<p>Please review it in the Tri-AR Credit Notes page.</p>'
            . '</div>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
