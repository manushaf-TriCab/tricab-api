<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * HTML and plain-text bodies for guest login OTP emails.
 */
final class GuestOtpEmailTemplate
{
    public const SUBJECT = 'Your TriCab guest login code';

    public const SESSION_DURATION_HOURS = 24;

    public static function subject(): string
    {
        return self::SUBJECT;
    }

    public static function html(OtpCode $otpCode, DateTimeImmutable $expiresAtUtc): string
    {
        $code = htmlspecialchars($otpCode->toString(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $minutes = self::expiryMinutes($expiresAtUtc);
        $expiresLabel = htmlspecialchars(
            $expiresAtUtc->setTimezone(new DateTimeZone('UTC'))->format('H:i \U\T\C'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $hours = (string) self::SESSION_DURATION_HOURS;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TriCab guest login code</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Helvetica,Arial,sans-serif;color:#1a1a1a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border-radius:8px;padding:32px 28px;">
          <tr>
            <td style="font-size:22px;font-weight:700;letter-spacing:0.02em;color:#0b3d2e;">
              TriCab
            </td>
          </tr>
          <tr>
            <td style="padding-top:20px;font-size:16px;line-height:1.5;">
              Use this one-time code to continue as a guest:
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:28px 0;">
              <div style="display:inline-block;font-size:32px;font-weight:700;letter-spacing:0.35em;font-family:Consolas,Monaco,monospace;color:#0b3d2e;">
                {$code}
              </div>
            </td>
          </tr>
          <tr>
            <td style="font-size:14px;line-height:1.6;color:#333333;">
              This code expires in about {$minutes} minutes (by {$expiresLabel}).
              It can be used only once.
              After you sign in, your guest session lasts up to {$hours} hours.
            </td>
          </tr>
          <tr>
            <td style="padding-top:20px;font-size:13px;line-height:1.5;color:#666666;">
              If you did not request this code, you can ignore this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    public static function text(OtpCode $otpCode, DateTimeImmutable $expiresAtUtc): string
    {
        $minutes = self::expiryMinutes($expiresAtUtc);
        $expiresLabel = $expiresAtUtc->setTimezone(new DateTimeZone('UTC'))->format('H:i \U\T\C');
        $hours = (string) self::SESSION_DURATION_HOURS;

        return implode("\n", [
            'TriCab',
            '',
            'Your guest login code is:',
            '',
            $otpCode->toString(),
            '',
            sprintf('This code expires in about %d minutes (by %s).', $minutes, $expiresLabel),
            'It can be used only once.',
            sprintf('After you sign in, your guest session lasts up to %s hours.', $hours),
            '',
            'If you did not request this code, you can ignore this email.',
        ]);
    }

    public static function expiryMinutes(DateTimeImmutable $expiresAtUtc): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = $expiresAtUtc->getTimestamp() - $now->getTimestamp();

        return max(1, (int) ceil($seconds / 60));
    }
}
