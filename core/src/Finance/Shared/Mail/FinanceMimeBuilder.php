<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

use DateTimeImmutable;
use DateTimeZone;

/**
 * An RFC 5322 / MIME message for a finance message that carries a calendar invite, CRLF throughout:
 *
 *   multipart/mixed                       (only when there are attachments)
 *     multipart/alternative
 *       text/plain; charset=UTF-8
 *       text/html; charset=UTF-8
 *       text/calendar; charset=UTF-8; method=REQUEST
 *     each attachment (invite.ics as application/ics, as Google Calendar sends it)
 *
 * Every body part is base64 with 76-character lines. A non-ASCII subject is an RFC 2047 encoded word (split so no line
 * passes 76 characters and no character is cut). Headers cannot carry a line break.
 */
final class FinanceMimeBuilder
{
    public const DOMAIN = 'tricab.ai';

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public static function build(FinanceMailMessage $message, array $to, array $cc, DateTimeImmutable $date, string $messageId, string $boundarySeed): string
    {
        $calendar = $message->calendar ?? throw new \InvalidArgumentException('A MIME message needs a calendar part.');
        $alt = 'alt_' . $boundarySeed;
        $mixed = 'mix_' . $boundarySeed;
        $headers = [
            'From: ' . self::line($message->mailbox),
            'To: ' . implode(', ', array_map(self::line(...), $to)),
        ];
        if ($cc !== []) {
            $headers[] = 'Cc: ' . implode(', ', array_map(self::line(...), $cc));
        }
        $headers[] = 'Subject: ' . self::subject($message->subject);
        $headers[] = 'Date: ' . $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s \+0000');
        $headers[] = 'Message-ID: <' . preg_replace('/[^A-Za-z0-9._-]/', '', $messageId) . '@' . self::DOMAIN . '>';
        $headers[] = 'MIME-Version: 1.0';

        $alternative = self::multipart($alt, [
            self::part('text/plain; charset=UTF-8', $calendar->text),
            self::part('text/html; charset=UTF-8', $message->body),
            self::part('text/calendar; charset=UTF-8; method=REQUEST', $calendar->ics),
        ]);

        if ($message->attachments === []) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';

            return implode("\r\n", $headers) . "\r\n\r\n" . $alternative;
        }
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
        $inner = "Content-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n" . $alternative;
        $parts = "--{$mixed}\r\n" . $inner;
        foreach ($message->attachments as $attachment) {
            $name = str_replace(['"', "\r", "\n", '\\'], '', $attachment->name);
            $type = str_starts_with(strtolower($attachment->contentType), 'text/calendar') ? 'application/ics' : $attachment->contentType;
            $parts .= "--{$mixed}\r\nContent-Type: " . self::line($type) . "; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . self::base64($attachment->content);
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $parts . "--{$mixed}--\r\n";
    }

    /** One alternative part, headed and base64-encoded. */
    private static function part(string $type, string $content): string
    {
        return "Content-Type: {$type}\r\nContent-Transfer-Encoding: base64\r\n\r\n" . self::base64($content);
    }

    /** @param list<string> $parts each headed part, ending in a CRLF */
    private static function multipart(string $boundary, array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= "--{$boundary}\r\n" . $part;
        }

        return $out . "--{$boundary}--\r\n";
    }

    private static function base64(string $content): string
    {
        return chunk_split(base64_encode($content), 76, "\r\n");
    }

    private static function line(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    /** The subject as a header value: plain when ASCII, else RFC 2047 encoded words of at most 45 bytes each, joined by a folded space. */
    public static function subject(string $subject): string
    {
        $subject = self::line($subject);
        if (preg_match('/[^\x20-\x7E]/', $subject) !== 1) {
            return $subject;
        }
        $words = [];
        $current = '';
        foreach (mb_str_split($subject, 1, 'UTF-8') as $char) {
            if ($current !== '' && strlen($current . $char) > 45) {
                $words[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $words[] = $current;

        return implode("\r\n ", array_map(static fn (string $w): string => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
    }
}
