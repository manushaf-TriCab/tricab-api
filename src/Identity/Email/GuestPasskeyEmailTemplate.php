<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

final class GuestPasskeyEmailTemplate
{
    public static function subject(): string
    {
        return 'Set up your TriCab passkey';
    }

    public static function html(string $setupUrl): string
    {
        $url = htmlspecialchars($setupUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#eef2f0;font-family:Helvetica,Arial,sans-serif;color:#1a1a1a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border-radius:10px;border:1px solid #d9e2dc;">
        <tr><td style="background:#0b3d2e;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:700;">TriCab</td></tr>
        <tr><td style="padding:28px;font-size:16px;line-height:1.55;">
          <p>You requested secure passwordless access to TriCab.</p>
          <p>Use the button below to create a passkey using Face ID, Touch ID, your Android device, or another supported passkey provider.</p>
          <p style="text-align:center;margin:28px 0;">
            <a href="{$url}" style="background:#F58220;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;display:inline-block;font-weight:700;">Set up passkey</a>
          </p>
          <p>This link can only be used once and expires in 15 minutes.</p>
          <p>If you did not request this, you can ignore this email.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    public static function text(string $setupUrl): string
    {
        return "Set up your TriCab passkey\n\nYou requested secure passwordless access to TriCab.\n\n{$setupUrl}\n\nThis link can only be used once and expires in 15 minutes.\nIf you did not request this, you can ignore this email.\n";
    }
}
