<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Transform;

use TriCab\Core\Product\Migration\Canonical\CanonicalSpecificationValue;

/**
 * Transforms legacy specification values. Never silently coerces ambiguous values.
 */
final class SpecificationValueTransformer
{
    private const AMBIGUOUS = [
        'n/a', 'na', 'unknown', 'approx', 'approximately', 'ish', '~', 'tbc', 'tba', '-',
    ];

    /**
     * @return CanonicalSpecificationValue
     */
    public function transform(
        string $sourceField,
        string $rawValue,
        string $rule,
        ?string $definitionCode,
        ?string $unitCode,
        string $scope,
        ?callable $optionResolver = null,
    ): CanonicalSpecificationValue {
        $trimmed = trim($rawValue);
        if ($trimmed === '') {
            return new CanonicalSpecificationValue(
                $sourceField,
                $definitionCode,
                $rawValue,
                null,
                $unitCode,
                $scope,
                'missing',
                'Empty source value',
            );
        }

        $lower = strtolower($trimmed);
        foreach (self::AMBIGUOUS as $token) {
            if ($lower === $token || str_contains($lower, $token . ' ') || str_ends_with($lower, '-ish') || str_starts_with($lower, '~')) {
                return new CanonicalSpecificationValue(
                    $sourceField,
                    $definitionCode,
                    $rawValue,
                    null,
                    $unitCode,
                    $scope,
                    'manual-review',
                    'Ambiguous value rejected: no silent coercion',
                );
            }
        }

        return match ($rule) {
            'integer' => $this->integer($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope),
            'decimal', 'measurement' => $this->decimal($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope),
            'boolean' => $this->boolean($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope),
            'date' => $this->date($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope),
            'option' => $this->option($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope, $optionResolver),
            'multi-option' => $this->multiOption($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope, $optionResolver),
            'range' => $this->range($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope),
            'url' => $this->url($sourceField, $rawValue, $trimmed, $definitionCode, $unitCode, $scope),
            default => new CanonicalSpecificationValue(
                $sourceField,
                $definitionCode,
                $rawValue,
                $trimmed,
                $unitCode,
                $scope,
                'preserved',
                null,
            ),
        };
    }

    private function integer(string $f, string $raw, string $t, ?string $d, ?string $u, string $s): CanonicalSpecificationValue
    {
        if (!preg_match('/^-?\d+$/', $t)) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'blocking-error', 'Invalid integer');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, $t, $u, $s, 'preserved', null);
    }

    private function decimal(string $f, string $raw, string $t, ?string $d, ?string $u, string $s): CanonicalSpecificationValue
    {
        $replaced = preg_replace('/\s*mm\s*$/i', '', $t);
        $clean = is_string($replaced) ? $replaced : $t;
        if (!preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'manual-review', 'Non-numeric or ambiguous decimal');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, $clean, $u, $s, 'preserved', null);
    }

    private function boolean(string $f, string $raw, string $t, ?string $d, ?string $u, string $s): CanonicalSpecificationValue
    {
        $map = ['1' => 'true', '0' => 'false', 'true' => 'true', 'false' => 'false', 'yes' => 'true', 'no' => 'false'];
        $key = strtolower($t);
        if (!isset($map[$key])) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'manual-review', 'Invalid boolean');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, $map[$key], $u, $s, 'preserved', null);
    }

    private function date(string $f, string $raw, string $t, ?string $d, ?string $u, string $s): CanonicalSpecificationValue
    {
        $ts = strtotime($t);
        if ($ts === false) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'blocking-error', 'Invalid date');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, gmdate('Y-m-d', $ts), $u, $s, 'normalized', null);
    }

    private function option(string $f, string $raw, string $t, ?string $d, ?string $u, string $s, ?callable $resolver): CanonicalSpecificationValue
    {
        if ($resolver === null) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'manual-review', 'No option alias resolver');
        }
        $resolved = $resolver($d, $t);
        $code = is_string($resolved) ? $resolved : null;
        if ($code === null) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'manual-review', 'Unknown option; will not auto-create');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, $code, $u, $s, 'preserved', null);
    }

    private function multiOption(string $f, string $raw, string $t, ?string $d, ?string $u, string $s, ?callable $resolver): CanonicalSpecificationValue
    {
        $parts = preg_split('/\s*,\s*/', $t) ?: [];
        $codes = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $one = $this->option($f, $part, $part, $d, $u, $s, $resolver);
            if ($one->outcome !== 'preserved' || $one->transformedValue === null) {
                return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'manual-review', 'Unresolved multi-option value: ' . $part);
            }
            $codes[] = $one->transformedValue;
        }

        return new CanonicalSpecificationValue($f, $d, $raw, implode(',', $codes), $u, $s, 'preserved', null);
    }

    private function range(string $f, string $raw, string $t, ?string $d, ?string $u, string $s): CanonicalSpecificationValue
    {
        if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*[-–to]+\s*(-?\d+(?:\.\d+)?)$/i', $t, $m)) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'manual-review', 'Invalid range');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, $m[1] . '-' . $m[2], $u, $s, 'normalized', null);
    }

    private function url(string $f, string $raw, string $t, ?string $d, ?string $u, string $s): CanonicalSpecificationValue
    {
        if (filter_var($t, FILTER_VALIDATE_URL) === false) {
            return new CanonicalSpecificationValue($f, $d, $raw, null, $u, $s, 'blocking-error', 'Invalid URL');
        }

        return new CanonicalSpecificationValue($f, $d, $raw, $t, $u, $s, 'preserved', null);
    }
}
