<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Email;

/**
 * The invitation to start onboarding (4.1).
 *
 * What it must not contain is the point of it: **no code** (invariant 6), and
 * nothing about the person beyond the first word of the name HR entered. It
 * says where to go and how signing in works, so a new starter who has never
 * used TriCab knows a code will arrive when they ask for one — and that nobody
 * from TriCab will ask them to read it out.
 */
final class OnboardingInvitationEmailTemplate
{
    public static function subject(): string
    {
        return 'Your TriCab onboarding is ready';
    }

    public static function html(string $displayName, string $onboardingUrl): string
    {
        $url = htmlspecialchars($onboardingUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $greeting = htmlspecialchars(self::greeting($displayName), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#eef2f0;font-family:Helvetica,Arial,sans-serif;color:#1a1a1a;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border-radius:10px;border:1px solid #d9e2dc;">
        <tr><td style="background:#0b3d2e;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:700;">TriCab</td></tr>
        <tr><td style="padding:28px;font-size:16px;line-height:1.55;">
          <p>{$greeting}</p>
          <p>Your onboarding with TriCab is ready: the training, policies and forms to complete before and after you start.</p>
          <p style="text-align:center;margin:28px 0;">
            <a href="{$url}" style="background:#b8520a;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;display:inline-block;font-weight:700;">Open my onboarding</a>
          </p>
          <p>Sign in with the email address this message was sent to. If you sign in with a code, ask for it on the sign-in page and it will be emailed to you.</p>
          <p>TriCab will never ask you to tell anyone your code.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    public static function text(string $displayName, string $onboardingUrl): string
    {
        return self::greeting($displayName) . "\n\n"
            . "Your onboarding with TriCab is ready: the training, policies and forms to complete before and after you start.\n\n"
            . "Open it here: {$onboardingUrl}\n\n"
            . "Sign in with the email address this message was sent to. If you sign in with a code, ask for it on the sign-in page and it will be emailed to you.\n"
            . "TriCab will never ask you to tell anyone your code.\n";
    }

    private static function greeting(string $displayName): string
    {
        $first = strtok(trim($displayName), " \t") ?: '';

        return $first === '' ? 'Hello,' : sprintf('Hello %s,', $first);
    }
}
