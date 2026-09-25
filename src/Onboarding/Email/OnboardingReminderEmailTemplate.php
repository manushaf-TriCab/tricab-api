<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Email;

use TriCab\Core\Onboarding\Application\DTO\Reminder\ReminderDigestItem;

/**
 * The reminder digest (5.2), ported from the source's `sendTrainingDigest`.
 *
 * Titles and due dates, a link, and nothing else: no code (invariant 6), no
 * answers, no form values, no HR notes. It is written in the invitation's
 * shell so the two read as one service.
 */
final class OnboardingReminderEmailTemplate
{
    /**
     * @param non-empty-list<ReminderDigestItem> $items
     */
    public static function subject(array $items): string
    {
        return count($items) === 1
            ? sprintf('TriCab onboarding reminder: %s', $items[0]->title)
            : sprintf('TriCab onboarding reminder: %d items outstanding', count($items));
    }

    /**
     * @param non-empty-list<ReminderDigestItem> $items
     */
    public static function html(string $displayName, array $items, string $onboardingUrl): string
    {
        $url = self::e($onboardingUrl);
        $greeting = self::e(self::greeting($displayName));
        $lead = self::e(self::lead($items));
        $list = '';
        foreach ($items as $item) {
            $colour = $item->overdue ? '#b3261e' : '#555555';
            $list .= sprintf(
                '<li style="margin:0 0 12px;"><span style="font-weight:700;">%s</span><br><span style="font-size:14px;color:%s;">%s</span></li>',
                self::e($item->title),
                $colour,
                self::e(self::timing($item)),
            );
        }

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
          <p>{$lead}</p>
          <ul style="margin:0 0 20px;padding-left:20px;">{$list}</ul>
          <p style="text-align:center;margin:28px 0;">
            <a href="{$url}" style="background:#b8520a;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;display:inline-block;font-weight:700;">Continue my onboarding</a>
          </p>
          <p>TriCab will never ask you to tell anyone your sign-in code.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * @param non-empty-list<ReminderDigestItem> $items
     */
    public static function text(string $displayName, array $items, string $onboardingUrl): string
    {
        $lines = array_map(static fn (ReminderDigestItem $item): string => sprintf('- %s (%s)', $item->title, self::timing($item)), $items);

        return self::greeting($displayName) . "\n\n"
            . self::lead($items) . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Continue here: {$onboardingUrl}\n\n"
            . "TriCab will never ask you to tell anyone your sign-in code.\n";
    }

    /** @param non-empty-list<ReminderDigestItem> $items */
    private static function lead(array $items): string
    {
        return count($items) === 1
            ? 'This onboarding item is still waiting for you:'
            : sprintf('These %d onboarding items are still waiting for you:', count($items));
    }

    private static function timing(ReminderDigestItem $item): string
    {
        if ($item->dueDate === null) {
            return 'No due date set';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $item->dueDate);
        $shown = $date === false ? $item->dueDate : $date->format('j F Y');

        return $item->overdue ? sprintf('Overdue — was due %s', $shown) : sprintf('Due %s', $shown);
    }

    private static function greeting(string $displayName): string
    {
        $first = strtok(trim($displayName), " \t") ?: '';

        return $first === '' ? 'Hello,' : sprintf('Hello %s,', $first);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
