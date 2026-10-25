<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * An iCalendar invite (RFC 5545 with the iTIP `METHOD` of RFC 5546) for one task's due date: an all-day event
 * (`DTSTART;VALUE=DATE`, `DTEND` the next day, as the RFC defines an all-day event's end), CRLF line ends, text
 * escaped and lines folded at 75 octets without splitting a UTF-8 character.
 *
 * The `UID` is stable per task and the `SEQUENCE` goes up on every re-send, which is what makes a calendar update the
 * event it already holds instead of adding a second one. The method is `REQUEST`, which creates or updates (a task deleted after an invite is not cancelled: nothing sends `CANCEL`).
 */
final class TaskIcs
{
    /** The same event every time for one task: the task id at the app's domain. */
    public static function uid(string $taskId): string
    {
        return 'tri-task-' . $taskId . '@tricab.ai';
    }

    /**
     * @param list<array{name: string, email: string}> $attendees
     * @param array{name: string, email: string} $organizer
     */
    public static function invite(
        string $uid,
        int $sequence,
        string $dueDate,
        string $summary,
        string $description,
        string $url,
        array $organizer,
        array $attendees,
        DateTimeImmutable $stamp,
    ): string {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new DateTimeZone('UTC'));
        if ($day === false) {
            throw new \InvalidArgumentException('A due date must be yyyy-MM-dd.');
        }
        $stampUtc = $stamp->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//TriCab//Tri-Task//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $stampUtc,
            'LAST-MODIFIED:' . $stampUtc,
            'SEQUENCE:' . max(0, $sequence),
            'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
            'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd'),
            'SUMMARY:' . self::text($summary),
            'DESCRIPTION:' . self::text($description),
            'URL:' . self::uri($url),
            'ORGANIZER;CN=' . self::param($organizer['name'] !== '' ? $organizer['name'] : $organizer['email']) . ':mailto:' . self::uri($organizer['email']),
        ];
        foreach ($attendees as $attendee) {
            $lines[] = 'ATTENDEE;CN=' . self::param($attendee['name'] !== '' ? $attendee['name'] : $attendee['email'])
                . ';CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . self::uri($attendee['email']);
        }
        // A due date is a reminder, not a meeting: it must not mark anyone busy for the day.
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'X-MICROSOFT-CDO-ALLDAYEVENT:TRUE';
        $lines[] = 'X-MICROSOFT-CDO-BUSYSTATUS:FREE';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(static fn (string $line): string => self::fold($line), $lines));
    }

    /** A TEXT value: backslash, semicolon, comma and line breaks escaped (RFC 5545 section 3.3.11), other control characters dropped. */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $value);
    }

    /** A parameter value in double quotes (a name with a comma or colon stays whole); quotes and control characters cannot appear in one. */
    private static function param(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);

        return '"' . str_replace('"', "'", trim($value)) . '"';
    }

    /** A URI value: no control characters or spaces, so it cannot break out of its line. */
    private static function uri(string $value): string
    {
        return (string) preg_replace('/[\x00-\x20\x7F]/', '', $value);
    }

    /** One content line folded at 75 octets, each continuation starting with a space, ended by CRLF. */
    public static function fold(string $line): string
    {
        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            // Never cut inside a multi-byte character: back up to its first byte.
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                --$cut;
            }
            $out .= substr($line, 0, $cut) . "\r\n";
            $line = ' ' . substr($line, $cut);
            $limit = 75;
        }

        return $out . $line . "\r\n";
    }
}
