<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Task\Service\TaskIcs;

/** The iCalendar text itself (RFC 5545): escaping, folding, line ends, the all-day dates and the stable UID. */
final class TaskIcsTest extends TestCase
{
    public function test_the_uid_is_the_task_id_at_the_app_domain_and_never_changes(): void
    {
        self::assertSame('tri-task-abc123@tricab.ai', TaskIcs::uid('abc123'));
        self::assertSame(TaskIcs::uid('abc123'), TaskIcs::uid('abc123'));
    }

    public function test_text_escapes_backslash_semicolon_comma_and_newlines_and_drops_control_characters(): void
    {
        self::assertSame('a\\\\b\;c\\,d\\ne\\nf', TaskIcs::text("a\\b;c,d\ne\r\nf"));
        self::assertSame('ab', TaskIcs::text("a\x00\x07b"));
        self::assertSame('Clôture — 日本', TaskIcs::text('Clôture — 日本'));
    }

    public function test_fold_breaks_at_75_octets_with_a_leading_space_and_unfolds_to_the_original(): void
    {
        $line = 'SUMMARY:' . str_repeat('é', 100) . str_repeat('x', 50);
        $folded = TaskIcs::fold($line);

        $parts = explode("\r\n", rtrim($folded, "\r\n"));
        self::assertGreaterThan(1, count($parts));
        foreach ($parts as $i => $part) {
            self::assertLessThanOrEqual(75, strlen($part));
            self::assertTrue(mb_check_encoding($part, 'UTF-8'), 'a character is never split');
            self::assertSame($i > 0, str_starts_with($part, ' '));
        }
        self::assertSame($line, preg_replace('/\r\n /', '', rtrim($folded, "\r\n")));
        self::assertSame("SHORT\r\n", TaskIcs::fold('SHORT'));
    }

    public function test_an_all_day_event_ends_the_next_day_even_across_a_month_and_year(): void
    {
        $ics = TaskIcs::invite('u@x', 3, '2026-12-31', 'S', 'D', 'https://tricab.ai/x', ['name' => 'O', 'email' => 'o@x.test'], [['name' => '', 'email' => 'a@x.test']], new DateTimeImmutable('2026-10-25 01:02:03', new \DateTimeZone('Australia/Melbourne')));

        self::assertStringContainsString("DTSTART;VALUE=DATE:20261231\r\nDTEND;VALUE=DATE:20270101\r\n", $ics);
        self::assertStringContainsString("DTSTAMP:20261024T140203Z\r\n", $ics, 'the stamp is UTC');
        self::assertStringContainsString("SEQUENCE:3\r\n", $ics);
        self::assertStringContainsString('ATTENDEE;CN="a@x.test";', $ics, 'an address stands in for a missing name');
    }

    public function test_a_name_cannot_break_out_of_its_parameter_or_line(): void
    {
        $ics = TaskIcs::invite('u@x', 0, '2026-10-30', "S\r\nATTENDEE:mailto:evil@x.test", 'D', 'https://tricab.ai/x', ['name' => "O\"; X=1\r\nBAD:1", 'email' => 'o@x.test'], [], new DateTimeImmutable('2026-10-25'));

        self::assertStringNotContainsString("\r\nATTENDEE:mailto:evil", $ics);
        self::assertStringNotContainsString("\r\nBAD:1", $ics);
        self::assertStringContainsString('SUMMARY:S\\nATTENDEE:mailto:evil@x.test', str_replace("\r\n ", '', $ics));
    }

    public function test_a_bad_due_date_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskIcs::invite('u@x', 0, '30/10/2026', 'S', 'D', 'u', ['name' => 'O', 'email' => 'o@x.test'], [], new DateTimeImmutable());
    }
}
