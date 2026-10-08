<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use TriCab\Core\Finance\Ar\Domain\ArRecord;

/**
 * The overdue reminder, as the source's `dispatch_overdue_reminders` wrote it, with every value HTML-escaped (decision
 * D46: the source put the customer's name and subject into the HTML as typed).
 */
final class ArReminderMail
{
    private function __construct()
    {
    }

    public static function subject(ArRecord $record): string
    {
        $customer = trim($record->customerName) !== '' ? $record->customerName : 'Customer';

        return ArCreditNoteMail::subject('OVERDUE ACTION: Follow-up required for ' . $customer . ' (' . $record->subject . ')');
    }

    public static function body(ArRecord $record): string
    {
        return '<div style="font-family: Arial, sans-serif; color: #1e293b;">'
            . '<p><strong>Overdue Follow-up Reminder</strong></p>'
            . '<p>An AR follow-up item has elapsed its due date (' . self::e((string) $record->dueDate) . '):</p>'
            . '<ul>'
            . '<li><strong>Customer:</strong> ' . self::e($record->customerName) . '</li>'
            . '<li><strong>Subject:</strong> ' . self::e($record->subject) . '</li>'
            . '<li><strong>Category:</strong> ' . self::e($record->followUpCategory) . '</li>'
            . '<li><strong>Status:</strong> ' . self::e(ArRecord::STATUS_OVERDUE) . '</li>'
            . '</ul>'
            . '<p>Please review and complete this action in the Tri-AR Register.</p>'
            . '</div>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
