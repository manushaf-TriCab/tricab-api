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

    /** Inline SVG wordmark used when no hosted logo URL is configured. */
    private const DEFAULT_LOGO_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNjAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCAxNjAgNDAiIHJvbGU9ImltZyI+PHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiByeD0iOCIgZmlsbD0iIzBiM2QyZSIvPjx0ZXh0IHg9IjIwIiB5PSIyNiIgZm9udC1mYW1pbHk9IkhlbHZldGljYSxBcmlhbCxzYW5zLXNlcmlmIiBmb250LXNpemU9IjE4IiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5UPC90ZXh0Pjx0ZXh0IHg9IjUyIiB5PSIyNiIgZm9udC1mYW1pbHk9IkhlbHZldGljYSxBcmlhbCxzYW5zLXNlcmlmIiBmb250LXNpemU9IjIyIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjMGIzZDJlIj5UcmlDYWI8L3RleHQ+PC9zdmc+';

    public static function subject(): string
    {
        return self::SUBJECT;
    }

    public static function html(
        OtpCode $otpCode,
        DateTimeImmutable $expiresAtUtc,
        ?string $logoUrl = null,
    ): string {
        $code = htmlspecialchars($otpCode->toString(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $minutes = self::expiryMinutes($expiresAtUtc);
        $expiresLabel = htmlspecialchars(
            $expiresAtUtc->setTimezone(new DateTimeZone('UTC'))->format('H:i \U\T\C'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $hours = (string) self::SESSION_DURATION_HOURS;
        $logoSrc = htmlspecialchars(self::resolveLogoUrl($logoUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TriCab guest login code</title>
</head>
<body style="margin:0;padding:0;background:#eef2f0;font-family:Helvetica,Arial,sans-serif;color:#1a1a1a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f0;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #d9e2dc;">
          <tr>
            <td style="background:#0b3d2e;padding:22px 28px;">
              <img src="{$logoSrc}" alt="TriCab" width="160" height="40" style="display:block;border:0;outline:none;text-decoration:none;height:40px;width:auto;max-width:180px;">
            </td>
          </tr>
          <tr>
            <td style="padding:32px 28px 8px;font-size:16px;line-height:1.55;color:#1a1a1a;">
              Hello,
            </td>
          </tr>
          <tr>
            <td style="padding:0 28px;font-size:16px;line-height:1.55;color:#1a1a1a;">
              Use this one-time code to sign in to TriCab as a guest:
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:28px;">
              <div style="display:inline-block;background:#f3f7f5;border:1px solid #c9d9d1;border-radius:8px;padding:16px 28px;font-size:34px;font-weight:700;letter-spacing:0.4em;font-family:Consolas,Monaco,monospace;color:#0b3d2e;">
                {$code}
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 28px 8px;font-size:14px;line-height:1.6;color:#333333;">
              This code expires in <strong>{$minutes} minutes</strong> (by {$expiresLabel}).
              It can be used only once.
              After you sign in, your guest session lasts up to {$hours} hours.
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff8e8;border:1px solid #f0d9a0;border-radius:6px;">
                <tr>
                  <td style="padding:14px 16px;font-size:13px;line-height:1.55;color:#5c4a12;">
                    <strong>Security tip:</strong> TriCab will never ask you to share this code by phone, chat, or email.
                    Enter it only in the official TriCab sign-in screen.
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 28px 28px;font-size:13px;line-height:1.5;color:#666666;">
              If you did not request this code, you can safely ignore this email.
              No changes will be made to your account.
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
            'Hello,',
            '',
            'Your guest login code is:',
            '',
            $otpCode->toString(),
            '',
            sprintf('This code expires in %d minutes (by %s).', $minutes, $expiresLabel),
            'It can be used only once.',
            sprintf('After you sign in, your guest session lasts up to %s hours.', $hours),
            '',
            'Security tip: TriCab will never ask you to share this code by phone, chat, or email.',
            '',
            'If you did not request this code, you can safely ignore this email.',
        ]);
    }

    public static function expiryMinutes(DateTimeImmutable $expiresAtUtc): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = $expiresAtUtc->getTimestamp() - $now->getTimestamp();

        return max(1, (int) ceil($seconds / 60));
    }

    private static function resolveLogoUrl(?string $logoUrl): string
    {
        $trimmed = $logoUrl === null ? '' : trim($logoUrl);
        if ($trimmed !== '' && preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        return self::DEFAULT_LOGO_DATA_URI;
    }
}
