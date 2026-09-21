<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\FormVersionNotClassified;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Splits a submitted form payload into its ordinary, payroll and restricted
 * parts, on the way in.
 *
 * ## Why on the way in
 *
 * Filtering on the way **out** is the wrong control. By then the bank account is
 * already sitting in the workflow schema, in every backup taken since, and in
 * every query result that forgot to filter. The split has to happen before the
 * first write, which is what this class is for: the ordinary remainder goes to
 * `onboarding.FormSubmissions`, and the payroll and restricted parts go to
 * `onboarding_hr.RestrictedSubmissionData`, keyed by the same public id.
 *
 * ## Fails closed
 *
 * A field arriving with no classification is refused, not defaulted. That is the
 * same rule {@see FormVersionPublishGuard} enforces at publish time, restated at
 * submission time because the two can drift: a form version published while its
 * access group was `hr` and later changed to `payroll` has unclassified fields
 * and a live allocation. Refusing here means the worst case is a submission
 * somebody has to fix, rather than a bank account written in clear.
 *
 * Paths are dotted and flat — `bank.accountNumber`, `emergencyContacts.0.phone`
 * — because a classification has to name a leaf. Classifying an object would
 * mean a field added inside it later inherits a decision nobody made about it.
 *
 * Pure: no database, no request, no clock.
 */
final class FormPayloadSplitter
{
    /**
     * @param array<string, mixed> $flatPayload      leaf paths to values
     * @param array<string, int>   $classifications  leaf path to DataClass value
     *
     * @return array{ordinary: array<string, mixed>, payroll: array<string, mixed>, restricted: array<string, mixed>}
     *
     * @throws FormVersionNotClassified when any submitted field has no classification
     */
    public static function split(array $flatPayload, array $classifications): array
    {
        $unclassified = [];
        foreach (array_keys($flatPayload) as $path) {
            if (!array_key_exists($path, $classifications)) {
                $unclassified[] = $path;
            }
        }

        if ($unclassified !== []) {
            throw FormVersionNotClassified::forFields($unclassified);
        }

        $split = ['ordinary' => [], 'payroll' => [], 'restricted' => []];

        foreach ($flatPayload as $path => $value) {
            $bucket = match ($classifications[$path]) {
                DataClass::PAYROLL => 'payroll',
                DataClass::RESTRICTED_HR => 'restricted',
                default => 'ordinary',
            };

            $split[$bucket][$path] = $value;
        }

        return $split;
    }

    /**
     * Whether a split produced anything for the restricted schema.
     *
     * This is what `onboarding.FormSubmissions.HasRestrictedData` records, and
     * it is stored rather than derived so that finding every submission with a
     * restricted half does not mean opening the restricted half to check.
     *
     * @param array{ordinary: array<string, mixed>, payroll: array<string, mixed>, restricted: array<string, mixed>} $split
     */
    public static function hasRestrictedData(array $split): bool
    {
        return $split['payroll'] !== [] || $split['restricted'] !== [];
    }

    /**
     * Flattens a nested payload to dotted leaf paths.
     *
     * An empty array is a leaf: `mealPreferences: []` is an answer somebody
     * gave, and dropping it would silently lose a field the classification
     * expects and turn a valid submission into an unclassified-field refusal.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                foreach (self::flatten($value, $path) as $nestedPath => $nestedValue) {
                    $flat[$nestedPath] = $nestedValue;
                }

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
