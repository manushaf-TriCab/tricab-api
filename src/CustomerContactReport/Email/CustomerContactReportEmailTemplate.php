<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Email;

use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotice;

/**
 * Outlook-safe HTML for Customer Contact mail. Every notice value is escaped.
 */
final class CustomerContactReportEmailTemplate
{
    private const NAVY = '#0b2545';

    private const ACCENT = '#F58220';

    private const BORDER = '#e5e7eb';

    /**
     * @return array{subject: string, html: string}
     */
    public function render(CustomerContactReportNotice $notice): array
    {
        $rows = '';
        $fields = $notice->fields;
        if ($notice->queueUrl !== null) {
            $fields['Your queue'] = $notice->queueUrl;
        }
        $index = 0;
        $last = count($fields) - 1;
        foreach ($fields as $label => $value) {
            $border = $index < $last ? 'border-bottom:1px solid ' . self::BORDER . ';' : '';
            $cell = $label === 'Your queue'
                ? '<a href="' . $this->escape($value) . '" style="color:' . self::ACCENT . ';">All Customer Contacts assigned to you</a>'
                : $this->escape($value);
            $rows .= '<tr>'
                . '<td style="padding:8px 0;' . $border . 'font:13px Segoe UI,Arial,sans-serif;color:#6b7280;width:150px;vertical-align:top;">'
                . $this->escape($label) . '</td>'
                . '<td style="padding:8px 0;' . $border . 'font:14px Segoe UI,Arial,sans-serif;color:#111827;vertical-align:top;">'
                . $cell . '</td></tr>';
            $index++;
        }

        $button = '';
        if ($notice->linkUrl !== null && $notice->linkLabel !== '') {
            $button = '<p style="margin:24px 0 0;"><a href="' . $this->escape($notice->linkUrl) . '" '
                . 'style="display:inline-block;background:' . self::ACCENT . ';color:#ffffff;padding:11px 24px;'
                . 'text-decoration:none;border-radius:5px;font:600 14px Segoe UI,Arial,sans-serif;">'
                . $this->escape($notice->linkLabel) . '</a></p>';
        }

        $html = '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:24px;background:#f3f4f6;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;margin:0 auto;">'
            . '<tr><td style="background:' . self::NAVY . ';padding:18px 28px;">'
            . '<span style="font:700 16px Segoe UI,Arial,sans-serif;color:#ffffff;">TriCab Customer Contact</span>'
            . '</td></tr>'
            . '<tr><td style="background:#ffffff;border:1px solid ' . self::BORDER . ';border-top:none;padding:28px;">'
            . '<p style="margin:0 0 20px;"><span style="display:inline-block;background:' . self::ACCENT
            . ';color:#ffffff;font:700 12px Segoe UI,Arial,sans-serif;padding:6px 14px;border-radius:4px;">'
            . $this->escape($notice->heading) . '</span></p>'
            . '<p style="margin:0 0 24px;font:15px/1.6 Segoe UI,Arial,sans-serif;color:#374151;">'
            . $this->escape($notice->intro) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $rows . '</table>'
            . $button
            . '</td></tr>'
            . '<tr><td style="padding:16px 8px 0;font:12px Segoe UI,Arial,sans-serif;color:#6b7280;">'
            . 'This is an automated message from TriCab Customer Contact — please do not reply.'
            . '</td></tr></table></body></html>';

        return [
            'subject' => $notice->subject,
            'html' => $html,
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
