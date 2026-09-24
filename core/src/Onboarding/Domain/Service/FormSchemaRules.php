<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * What an author may put in a form definition version (4.4): its fields, and
 * the data class of each one.
 *
 * The source had no form authoring at all — its forms were code. So there is
 * nothing to port, and the rules come from what the target already relies on:
 *
 *  - the field types the trainee form renders — six, and since 5.4 a drawn
 *    `signature` and `photo`s, which the paper forms need. A photo field is
 *    filled by HR (the vehicle inspection), never by the starter;
 *  - a dotted path per field, which {@see FormPayloadSplitter} splits on and
 *    `onboarding_hr.FieldClassifications` keys by;
 *  - **a classification for every field, recorded as the author saves**.
 *    {@see FormVersionPublishGuard} refuses to publish a payroll or restricted
 *    form with an unclassified field, and the splitter refuses to split one. An
 *    editor that let a field through unclassified would only move that refusal
 *    to the first new starter who pressed Submit.
 *
 * ## An `hr` form holds ordinary fields only
 *
 * By the access group's own definition none of its fields is payroll or
 * restricted, so every field is recorded as C2 and a C3 or C4 is refused. A form
 * that needs a bank account is a payroll form, and saying so is a decision about
 * who should see it, made once for the form rather than per field.
 *
 * ## Who fills a field (5.4)
 *
 * `filledBy` is `starter` (the default), `hr` or `manager`. The checklist and
 * the vehicle report have parts the new starter does not fill. Only an `hr`
 * form may have them, so everything somebody else writes is ordinary HR data
 * (C2) and nothing contributed ever reaches `onboarding_hr`.
 *
 * ## No path may contain another
 *
 * `bank` and `bank.bsb` cannot both be fields: when the answers are nested for
 * storage, one of them would overwrite the other.
 *
 * Pure: no database, no request, no clock.
 */
final class FormSchemaRules
{
    public const MAX_FIELDS = 100;
    public const MAX_OPTIONS = 50;
    public const MAX_LABEL_LENGTH = 240;
    public const MAX_HINT_LENGTH = 500;
    public const MAX_PATH_LENGTH = 200;

    public const FIELD_TYPES = ['text', 'textarea', 'date', 'number', 'select', 'checkbox', 'signature', 'photo'];

    /** The classes a submitted field can be — C0, C1 and C5 are never answers. */
    public const FIELD_CLASSES = [DataClass::ORDINARY_HR, DataClass::PAYROLL, DataClass::RESTRICTED_HR];

    public const FILLED_BY_STARTER = 'starter';
    public const FILLED_BY_HR = 'hr';
    public const FILLED_BY_MANAGER = 'manager';
    public const FILLED_BY = [self::FILLED_BY_STARTER, self::FILLED_BY_HR, self::FILLED_BY_MANAGER];

    private const PATH = '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)*$/';
    private const OPTION_KEY = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

    /**
     * @return array{fields: list<array<string, mixed>>, classifications: array<string, int>}
     *
     * @throws InvalidOnboardingValue naming the field that is wrong
     */
    public static function normalise(mixed $fields, string $accessGroup): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw InvalidOnboardingValue::for('form fields', 'not a list');
        }
        if (count($fields) > self::MAX_FIELDS) {
            throw InvalidOnboardingValue::for('form fields', sprintf('more than %d fields', self::MAX_FIELDS));
        }

        $normalised = [];
        $classifications = [];
        foreach ($fields as $index => $field) {
            $where = 'field ' . ($index + 1);
            [$stored, $class] = self::field($field, $where, $accessGroup);
            $path = $stored['path'];
            if (!is_string($path)) {
                continue;
            }
            foreach (array_keys($classifications) as $existing) {
                if ($existing === $path) {
                    throw InvalidOnboardingValue::for($where . ' name', $path . ' — used twice');
                }
                if (str_starts_with($existing, $path . '.') || str_starts_with($path, $existing . '.')) {
                    throw InvalidOnboardingValue::for($where . ' name', sprintf('%s overlaps %s — one would overwrite the other', $path, $existing));
                }
            }
            $normalised[] = $stored;
            $classifications[$path] = $class;
        }

        return ['fields' => $normalised, 'classifications' => $classifications];
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function field(mixed $field, string $where, string $accessGroup): array
    {
        if (!is_array($field)) {
            throw InvalidOnboardingValue::for($where, 'not an object');
        }

        $path = is_string($field['path'] ?? null) ? trim($field['path']) : '';
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH || preg_match(self::PATH, $path) !== 1) {
            throw InvalidOnboardingValue::for($where . ' name', $path === '' ? '(missing)' : $path . ' — letters, digits and underscores, dots between parts');
        }

        $label = self::text($field['label'] ?? null, $where . ' label', self::MAX_LABEL_LENGTH);
        if ($label === null) {
            throw InvalidOnboardingValue::required($where . ' label', 'every field needs one.');
        }

        $type = $field['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::FIELD_TYPES, true)) {
            throw InvalidOnboardingValue::forOneOf($where . ' type', is_string($type) ? $type : '(missing)', self::FIELD_TYPES);
        }

        $required = $field['required'] ?? false;
        if (!is_bool($required)) {
            throw InvalidOnboardingValue::for($where . ' required', '(not true or false)');
        }

        $filledBy = $field['filledBy'] ?? self::FILLED_BY_STARTER;
        if (!is_string($filledBy) || !in_array($filledBy, self::FILLED_BY, true)) {
            throw InvalidOnboardingValue::forOneOf($where . ' filled by', is_string($filledBy) ? $filledBy : '(not text)', self::FILLED_BY);
        }
        if ($filledBy !== self::FILLED_BY_STARTER && $accessGroup !== FormVersionPublishGuard::ACCESS_GROUP_HR) {
            throw InvalidOnboardingValue::for($where . ' filled by', 'only an HR form can have parts filled by HR or a manager');
        }

        if ($type === 'photo' && $filledBy !== self::FILLED_BY_HR) {
            throw InvalidOnboardingValue::for($where . ' filled by', 'photos are uploaded by HR, so a photo field is filled by HR');
        }

        $stored = ['path' => $path, 'label' => $label, 'type' => $type, 'required' => $required];
        if ($filledBy !== self::FILLED_BY_STARTER) {
            $stored['filledBy'] = $filledBy;
        }
        $hint = self::text($field['hint'] ?? null, $where . ' hint', self::MAX_HINT_LENGTH);
        if ($hint !== null) {
            $stored['hint'] = $hint;
        }
        if ($type === 'select') {
            $stored['options'] = self::options($field['options'] ?? null, $where);
        }
        if ($type === 'number') {
            $stored += self::numberBounds($field['min'] ?? null, $field['max'] ?? null, $where);
        }

        return [$stored, self::classification($field['dataClass'] ?? null, $where, $accessGroup)];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private static function options(mixed $options, string $where): array
    {
        if (!is_array($options) || !array_is_list($options) || $options === []) {
            throw InvalidOnboardingValue::required($where . ' choices', 'a drop-down needs at least one.');
        }
        if (count($options) > self::MAX_OPTIONS) {
            throw InvalidOnboardingValue::for($where . ' choices', sprintf('more than %d', self::MAX_OPTIONS));
        }

        $normalised = [];
        $keys = [];
        foreach ($options as $index => $option) {
            $optionWhere = sprintf('%s choice %d', $where, $index + 1);
            if (!is_array($option)) {
                throw InvalidOnboardingValue::for($optionWhere, 'not an object');
            }
            $key = is_string($option['key'] ?? null) ? trim($option['key']) : '';
            if ($key === '' || strlen($key) > 40 || preg_match(self::OPTION_KEY, $key) !== 1) {
                throw InvalidOnboardingValue::for($optionWhere . ' value', $key === '' ? '(missing)' : $key);
            }
            if (in_array($key, $keys, true)) {
                throw InvalidOnboardingValue::for($optionWhere . ' value', $key . ' — used twice');
            }
            $label = self::text($option['label'] ?? null, $optionWhere, self::MAX_LABEL_LENGTH);
            if ($label === null) {
                throw InvalidOnboardingValue::required($optionWhere, 'every choice needs wording.');
            }
            $keys[] = $key;
            $normalised[] = ['key' => $key, 'label' => $label];
        }

        return $normalised;
    }

    /**
     * Optional `min` and `max`. Without a `min`, {@see FormSubmissionRules}
     * refuses negatives, so an author only writes one to allow them.
     *
     * @return array{min?: int|float, max?: int|float}
     */
    private static function numberBounds(mixed $min, mixed $max, string $where): array
    {
        $bounds = [];
        foreach (['min' => $min, 'max' => $max] as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (!(is_int($value) || is_float($value)) || !is_finite((float) $value)) {
                throw InvalidOnboardingValue::for($where . ' ' . ($name === 'min' ? 'minimum' : 'maximum'), is_scalar($value) ? (string) $value : '(not a number)');
            }
            $bounds[$name] = $value;
        }
        if (isset($bounds['min'], $bounds['max']) && $bounds['min'] > $bounds['max']) {
            throw InvalidOnboardingValue::for($where . ' minimum', $bounds['min'] . ' — above the maximum ' . $bounds['max']);
        }

        return $bounds;
    }

    private static function classification(mixed $class, string $where, string $accessGroup): int
    {
        if (!FormVersionPublishGuard::requiresClassification($accessGroup)) {
            // An `hr` form: ordinary, and saying otherwise is refused rather
            // than quietly overwritten.
            if ($class !== null && $class !== DataClass::ORDINARY_HR) {
                throw InvalidOnboardingValue::for(
                    $where . ' data class',
                    'an HR form holds ordinary fields only — make it a payroll or restricted form to collect this',
                );
            }

            return DataClass::ORDINARY_HR;
        }

        if (!is_int($class) || !in_array($class, self::FIELD_CLASSES, true)) {
            throw InvalidOnboardingValue::required($where . ' data class', 'say whether it is ordinary, payroll or restricted.');
        }

        return $class;
    }

    private static function text(mixed $value, string $where, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidOnboardingValue::for($where, '(not text)');
        }
        $trimmed = trim($value);
        if (mb_strlen($trimmed) > $max) {
            throw InvalidOnboardingValue::for($where, sprintf('longer than %d characters', $max));
        }

        return $trimmed === '' ? null : $trimmed;
    }

    /** Who fills this stored field: `starter` unless it says otherwise. */
    public static function filledBy(mixed $field): string
    {
        $value = is_array($field) ? ($field['filledBy'] ?? null) : null;

        return is_string($value) && in_array($value, self::FILLED_BY, true) ? $value : self::FILLED_BY_STARTER;
    }
}
