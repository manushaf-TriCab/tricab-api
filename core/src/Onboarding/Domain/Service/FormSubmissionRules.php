<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * What a submitted answer must be, checked against the form version's own
 * schema before anything is written.
 *
 * The browser checks the same things, and that is a convenience: a request can
 * be sent without the browser, and a submission is immutable once written
 * (`TR_FormSubmissions_Immutable`), so a bad value accepted here is a bad value
 * a reviewer has to send back. Drafts are not checked — they are allowed to be
 * half-finished, which is what makes them drafts.
 *
 *  - a required field must have a value, and a required checkbox must be ticked;
 *  - a number is a finite number within the field's `min` and `max`. A field
 *    with no `min` refuses negatives: nothing an induction asks for — a boot
 *    size, hours, a count of dependants — is below zero, and a form that
 *    genuinely needs one can say `min`;
 *  - a select answer is one of the field's option keys;
 *  - a date is a real calendar date, `YYYY-MM-DD`;
 *  - a checkbox is true or false;
 *  - a signature is a drawn PNG of a signature's size ({@see \TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage});
 *  - text is text, within {@see MAX_TEXT_LENGTH} / {@see MAX_TEXTAREA_LENGTH};
 *  - an answer for a path the schema does not have is refused rather than
 *    stored — it would be data nobody asked for and no reviewer is shown.
 *
 * Pure: no database, no request, no clock.
 */
final class FormSubmissionRules
{
    public const MAX_TEXT_LENGTH = 1000;
    public const MAX_TEXTAREA_LENGTH = 10000;
    public const DEFAULT_NUMBER_MIN = 0;

    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * Only the fields `$filledBy` fills are checked, and an answer for any other
     * field is refused: the starter cannot write the manager's sign-off, and HR
     * cannot write the starter's answers (5.4).
     *
     * @param array<string, mixed> $schema      the version's schema, `{ fields: [...] }`
     * @param array<string, mixed> $flatPayload dotted leaf path => submitted value
     *
     * @throws InvalidOnboardingValue naming the first field that is wrong, by its label
     */
    public static function check(array $schema, array $flatPayload, string $filledBy = FormSchemaRules::FILLED_BY_STARTER): void
    {
        $fields = [];
        foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['path'] ?? null) && FormSchemaRules::filledBy($field) === $filledBy) {
                $fields[$field['path']] = $field;
            }
        }

        foreach (array_keys($flatPayload) as $path) {
            if (!array_key_exists($path, $fields)) {
                throw InvalidOnboardingValue::for('answer', $path . ' — not a field on this form');
            }
        }

        foreach ($fields as $path => $field) {
            self::field($path, $field, $flatPayload[$path] ?? null);
        }
    }

    /**
     * @param array<mixed> $field
     */
    private static function field(string $path, array $field, mixed $value): void
    {
        $label = is_string($field['label'] ?? null) ? $field['label'] : $path;
        $type = $field['type'] ?? 'text';

        if (self::isEmpty($type, $value)) {
            if (($field['required'] ?? false) === true) {
                throw InvalidOnboardingValue::required($label, $type === 'checkbox' ? 'it must be ticked.' : 'it cannot be left empty.');
            }

            return;
        }

        match ($type) {
            'number' => self::number($field, $label, $value),
            'select' => self::select($field, $label, $value),
            'date' => self::date($label, $value),
            'checkbox' => self::checkbox($label, $value),
            'signature' => \TriCab\Core\Onboarding\Domain\ValueObject\SignatureImage::fromDataUrl($label, $value),
            'photo' => \TriCab\Core\Onboarding\Domain\ValueObject\PhotoImage::idsFromAnswer($label, $value),
            'textarea' => self::text($label, $value, self::MAX_TEXTAREA_LENGTH),
            default => self::text($label, $value, self::MAX_TEXT_LENGTH),
        };
    }

    private static function isEmpty(mixed $type, mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || ($type === 'checkbox' && $value === false);
    }

    /**
     * @param array<mixed> $field
     */
    private static function number(array $field, string $label, mixed $value): void
    {
        $number = match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric(trim($value)) => (float) trim($value),
            default => null,
        };
        if ($number === null || !is_finite($number)) {
            throw InvalidOnboardingValue::for($label, self::shown($value) . ' — not a number');
        }

        $min = self::bound($field['min'] ?? null) ?? self::DEFAULT_NUMBER_MIN;
        if ($number < $min) {
            throw InvalidOnboardingValue::for($label, self::shown($value) . ' — must be at least ' . self::format($min));
        }
        $max = self::bound($field['max'] ?? null);
        if ($max !== null && $number > $max) {
            throw InvalidOnboardingValue::for($label, self::shown($value) . ' — must be at most ' . self::format($max));
        }
    }

    /**
     * @param array<mixed> $field
     */
    private static function select(array $field, string $label, mixed $value): void
    {
        $keys = [];
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_string($option['key'] ?? null)) {
                $keys[] = $option['key'];
            }
        }
        if (!is_string($value) || !in_array($value, $keys, true)) {
            throw InvalidOnboardingValue::forOneOf($label, self::shown($value), $keys);
        }
    }

    private static function date(string $label, mixed $value): void
    {
        if (
            !is_string($value)
            || preg_match(self::DATE, $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw InvalidOnboardingValue::for($label, self::shown($value) . ' — not a date (YYYY-MM-DD)');
        }
    }

    private static function checkbox(string $label, mixed $value): void
    {
        if (!is_bool($value)) {
            throw InvalidOnboardingValue::for($label, self::shown($value) . ' — not ticked or unticked');
        }
    }

    private static function text(string $label, mixed $value, int $max): void
    {
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($label, '(not text)');
        }
        if (mb_strlen($value) > $max) {
            throw InvalidOnboardingValue::for($label, sprintf('longer than %d characters', $max));
        }
    }

    private static function bound(mixed $bound): ?float
    {
        return is_int($bound) || is_float($bound) ? (float) $bound : null;
    }

    private static function format(float $number): string
    {
        return $number == floor($number) ? (string) (int) $number : (string) $number;
    }

    /** Enough of the value to recognise it, never a whole essay. */
    private static function shown(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : get_debug_type($value);

        return mb_strlen($text) > 40 ? mb_substr($text, 0, 40) . '…' : $text;
    }
}
