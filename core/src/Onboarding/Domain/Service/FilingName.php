<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * HR's filing name for a downloaded document:
 * `YY.MM.DD SURNAME Given names - Document name.ext`, dated by when the person
 * completed the work, on the Melbourne calendar.
 *
 * Ported from the source app's `filingFilename` (api/_lib/filing-name.ts), so
 * files saved from either system sort together. The platform holds one display
 * name, as the source held one full name: the last word is the surname and the
 * rest are given names, kept as typed. A part that is missing is left out, and
 * with nothing at all the name is "TriCab record".
 *
 * Pure: no clock, no request.
 */
final class FilingName
{
    public static function build(
        ?string $personName,
        ?string $completedAtUtc,
        ?string $documentName,
        string $extension,
    ): string {
        $person = implode(' ', array_filter(
            [self::date($completedAtUtc), self::person($personName ?? '')],
            static fn (string $part): bool => $part !== '',
        ));
        $stem = implode(' - ', array_filter(
            [$person, self::clean($documentName ?? '')],
            static fn (string $part): bool => $part !== '',
        ));

        return ($stem === '' ? 'TriCab record' : $stem) . '.' . $extension;
    }

    private static function date(?string $utc): string
    {
        if ($utc === null || trim($utc) === '') {
            return '';
        }

        try {
            $at = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return '';
        }

        return $at->setTimezone(new DateTimeZone(LeaverPolicy::LOCAL_TIMEZONE))->format('y.m.d');
    }

    private static function person(string $fullName): string
    {
        $words = array_values(array_filter(
            explode(' ', self::clean($fullName)),
            static fn (string $word): bool => $word !== '',
        ));
        $surname = array_pop($words);

        return $surname === null ? '' : implode(' ', [mb_strtoupper($surname, 'UTF-8'), ...$words]);
    }

    /** Characters a filesystem or a header would trip on become spaces, as in the source. */
    private static function clean(string $value): string
    {
        $value = preg_replace('/[<>:"\/\\\\|?*\p{Cc}]/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
