<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Task\Service\TaskDueDates;

/** Deadline words to dates, from a Wednesday (2026-10-07), the way the API resolves what the model reports. */
final class TaskDueDatesTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function phrases(): array
    {
        return [
            'today' => ['today', '2026-10-07'],
            'tomorrow' => ['Tomorrow', '2026-10-08'],
            'a weekday still to come' => ['Friday', '2026-10-09'],
            'by a weekday' => ['by Friday', '2026-10-09'],
            'the same weekday is today' => ['Wednesday', '2026-10-07'],
            'a weekday that has passed is next week' => ['Monday', '2026-10-12'],
            'next friday is the following week' => ['next Friday', '2026-10-16'],
            'next monday' => ['next Monday', '2026-10-12'],
            'end of week is Friday' => ['end of week', '2026-10-09'],
            'end of month' => ['end of month', '2026-10-31'],
            'eom' => ['by EOM', '2026-10-31'],
            'end of next month' => ['end of next month', '2026-11-30'],
            'end of quarter' => ['end of the quarter', '2026-12-31'],
            'end of financial year' => ['EOFY', '2027-06-30'],
            'in two weeks' => ['in 2 weeks', '2026-10-21'],
            'in a day' => ['in a day', '2026-10-08'],
            'end of a named month still to come' => ['end of January', '2027-01-31'],
            'end of the current month by name' => ['end of October', '2026-10-31'],
            'end of a named month that has passed' => ['end of Feb', '2027-02-28'],
            'day and month, next occurrence' => ['30 June', '2027-06-30'],
            'a date still to come this year' => ['15/11', '2026-11-15'],
            'a day that has passed this year is next year' => ['5 Oct', '2027-10-05'],
            'month first' => ['June 30th 2028', '2028-06-30'],
            'day month year' => ['15/11/2026', '2026-11-15'],
            'iso' => ['2026-12-01', '2026-12-01'],
            'not a real date' => ['31/02', null],
            'not a date at all' => ['when the audit is done', null],
            'zero days' => ['in 0 days', null],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('phrases')]
    public function test_a_phrase_resolves_or_is_left_alone(string $phrase, ?string $expected): void
    {
        self::assertSame($expected, (new TaskDueDates('2026-10-07'))->resolve($phrase));
    }

    public function test_on_a_friday_the_next_friday_is_a_week_on(): void
    {
        $dates = new TaskDueDates('2026-10-09');
        self::assertSame('2026-10-09', $dates->resolve('Friday'));
        self::assertSame('2026-10-16', $dates->resolve('next Friday'));
    }
}
