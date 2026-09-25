<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Onboarding\Email\OnboardingReminderEmailTemplate;
use TriCab\Core\Onboarding\Application\DTO\Reminder\ReminderDigestItem;

/** Phase 5.2 — the reminder digest says what is due and nothing else. */
final class OnboardingReminderEmailTemplateTest extends TestCase
{
    private const URL = 'https://tricab.ai/onboarding';

    public function test_one_item_is_named_in_the_subject_and_several_are_counted(): void
    {
        self::assertSame('TriCab onboarding reminder: WHS induction', OnboardingReminderEmailTemplate::subject([new ReminderDigestItem('WHS induction', null, false)]));
        self::assertSame('TriCab onboarding reminder: 2 items outstanding', OnboardingReminderEmailTemplate::subject([
            new ReminderDigestItem('WHS induction', null, false),
            new ReminderDigestItem('Leave Policy', '2026-09-20', true),
        ]));
    }

    public function test_each_item_says_when_it_was_due(): void
    {
        $text = OnboardingReminderEmailTemplate::text('Sam New', [
            new ReminderDigestItem('WHS induction', '2026-09-20', true),
            new ReminderDigestItem('Leave Policy', '2026-09-25', false),
            new ReminderDigestItem('Bank details', null, false),
        ], self::URL);

        self::assertStringContainsString('Hello Sam,', $text);
        self::assertStringContainsString('- WHS induction (Overdue — was due 20 September 2026)', $text);
        self::assertStringContainsString('- Leave Policy (Due 25 September 2026)', $text);
        self::assertStringContainsString('- Bank details (No due date set)', $text);
        self::assertStringContainsString(self::URL, $text);
    }

    public function test_titles_are_escaped_in_the_html(): void
    {
        $html = OnboardingReminderEmailTemplate::html('<b>Sam</b>', [new ReminderDigestItem('<script>x</script> & co', null, false)], self::URL);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt; &amp; co', $html);
        self::assertStringNotContainsString('<b>Sam</b>', $html);
    }

    public function test_it_carries_no_code_and_no_surname(): void
    {
        $html = OnboardingReminderEmailTemplate::html('Sam Newsurname', [new ReminderDigestItem('WHS induction', null, false)], self::URL);
        $text = OnboardingReminderEmailTemplate::text('Sam Newsurname', [new ReminderDigestItem('WHS induction', null, false)], self::URL);

        foreach ([$html, $text] as $body) {
            self::assertDoesNotMatchRegularExpression('/(?<![#\w])\d{6}\b/', $body, 'no six-digit code');
            self::assertStringNotContainsString('Newsurname', $body);
        }
    }
}
