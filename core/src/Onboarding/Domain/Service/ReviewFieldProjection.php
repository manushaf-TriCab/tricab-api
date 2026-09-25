<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * A submitted form as a reviewer reads it: each field's label beside what was
 * entered, with payroll and restricted fields named but never shown.
 *
 * ## Withheld means withheld, whatever the data says
 *
 * The ordinary half of a submission should not contain a C3 or C4 value —
 * {@see FormPayloadSplitter} took those out on the way in. This class does not
 * rely on that. A field classified C3 or C4 is withheld by its classification,
 * so a value that reached `SubmittedDataJson` by some other route (a
 * classification changed after the fact, a hand-edited row) is still not put
 * on a reviewer's screen. The two checks are independent, and a leak needs both
 * to fail.
 *
 * Restricted values are read through the audited document route (Phase 6), not
 * here: every read of one is owed a row in
 * `[audit].[OnboardingDocumentAccessEvents]`, and a review screen that renders
 * them would be a read nobody recorded.
 *
 * ## Fields the schema does not name
 *
 * Kept, labelled by their path, after the schema's own fields. A value the
 * person entered is evidence even if the form's schema has since lost the
 * field, and a reviewer who cannot see it is deciding on less than was handed
 * in. Unclassified extras are shown, because an unclassified field cannot have
 * been submitted at all — the splitter refuses them.
 *
 * Pure: no database, no request, no clock.
 */
final class ReviewFieldProjection
{
    /**
     * @param array<string, mixed> $schema          the form version's schema, `{fields: [{path, label, type, options?}]}`
     * @param array<string, mixed> $ordinaryData    flat dotted paths to values, as `FormSubmissions.SubmittedDataJson` holds them
     * @param array<string, int>   $classifications path to {@see DataClass} value
     *
     * @return list<array{path: string, label: string, value: ?string, withheld: bool}>
     */
    public static function project(array $schema, array $ordinaryData, array $classifications): array
    {
        $fields = [];
        $seen = [];

        foreach (self::schemaFields($schema) as $field) {
            $path = $field['path'];
            $seen[$path] = true;
            $value = $ordinaryData[$path] ?? null;
            // A drawn signature is an image, not text to read: the reviewer is
            // told it is there, and sees it on the generated paper copy.
            if ($field['type'] === 'signature' && is_string($value) && $value !== '') {
                $value = 'Signed (drawn signature)';
            }
            $fields[] = self::field($path, $field['label'], $value, $classifications, $field['options']);
        }

        foreach ($ordinaryData as $path => $value) {
            $path = (string) $path;
            if (isset($seen[$path])) {
                continue;
            }
            $fields[] = self::field($path, $path, $value, $classifications, []);
        }

        return $fields;
    }

    /**
     * @param array<string, int> $classifications
     * @param array<string, string> $options
     *
     * @return array{path: string, label: string, value: ?string, withheld: bool}
     */
    private static function field(string $path, string $label, mixed $value, array $classifications, array $options): array
    {
        $class = $classifications[$path] ?? null;
        if ($class !== null && in_array($class, DataClass::ALL, true) && DataClass::fromInt($class)->isRestricted()) {
            return ['path' => $path, 'label' => $label, 'value' => null, 'withheld' => true];
        }

        return ['path' => $path, 'label' => $label, 'value' => self::display($value, $options), 'withheld' => false];
    }

    /**
     * @param array<string, string> $options option key to its label
     */
    private static function display(mixed $value, array $options): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            $text = (string) $value;

            // A select stores the option's key; the reviewer should read what
            // the person saw.
            return $options[$text] ?? $text;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<array{path: string, label: string, type: string, options: array<string, string>}>
     */
    private static function schemaFields(array $schema): array
    {
        $raw = $schema['fields'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $fields = [];
        foreach ($raw as $field) {
            if (!is_array($field) || !is_string($field['path'] ?? null) || $field['path'] === '') {
                continue;
            }
            $label = $field['label'] ?? null;

            $options = [];
            if (is_array($field['options'] ?? null)) {
                foreach ($field['options'] as $option) {
                    if (is_array($option) && is_scalar($option['key'] ?? null) && is_string($option['label'] ?? null)) {
                        $options[(string) $option['key']] = $option['label'];
                    }
                }
            }

            $fields[] = [
                'path' => $field['path'],
                'label' => is_string($label) && $label !== '' ? $label : $field['path'],
                'type' => is_string($field['type'] ?? null) ? $field['type'] : 'text',
                'options' => $options,
            ];
        }

        return $fields;
    }
}
