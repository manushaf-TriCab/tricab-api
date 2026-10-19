<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;

/**
 * What the labelled-set export takes: `decidedOnly` keeps threads a person marked, and `from` / `to`
 * bound the *received* time of the assessed message. A bare date (`2026-10-01`) means that whole UTC
 * day, so `to=2026-10-31` includes the 31st; a full ISO time is taken as given. Both ends are inclusive.
 */
final readonly class ExportQuery
{
    public function __construct(
        public bool $decidedOnly = false,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
    ) {
    }

    /** @param array<string, mixed> $query the request's query string */
    public static function fromInput(array $query): self
    {
        $decided = strtolower(self::text($query['decidedOnly'] ?? ''));
        if (!in_array($decided, ['', '0', '1', 'true', 'false'], true)) {
            throw new MailInputInvalid('decidedOnly must be true or false.');
        }
        $from = self::moment($query['from'] ?? null, 'from', false);
        $to = self::moment($query['to'] ?? null, 'to', true);
        if ($from !== null && $to !== null && $from > $to) {
            throw new MailInputInvalid('from cannot be after to.');
        }

        return new self(in_array($decided, ['1', 'true'], true), $from, $to);
    }

    private static function moment(mixed $value, string $name, bool $endOfDay): ?DateTimeImmutable
    {
        $text = trim(self::text($value));
        if ($text === '') {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $utc);
            if ($day !== false && $day->format('Y-m-d') === $text) {
                return $endOfDay ? $day->setTime(23, 59, 59, 999999) : $day;
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?$/', $text) === 1) {
            try {
                return (new DateTimeImmutable($text, $utc))->setTimezone($utc);
            } catch (\Exception) {
                // falls through to the refusal below
            }
        }
        throw new MailInputInvalid(sprintf('%s must be a date (2026-10-31) or an ISO time.', $name));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
