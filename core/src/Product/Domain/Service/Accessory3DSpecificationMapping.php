<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

/**
 * Maps Product/Variant specification definition codes to a generic accessory
 * dimension allow-list for 3D generation. Never hardcodes definition ids.
 *
 * Dimensions are normalised to millimetres (unknown/empty unit is assumed mm).
 * MATERIALS is passed through as text.
 */
final class Accessory3DSpecificationMapping
{
    /** @var list<string> */
    public const DIMENSION_CODES = [
        'OVERALL-LENGTH',
        'WIDTH',
        'THICKNESS',
        'HEIGHT',
        'BARREL-LENGTH',
        'BARREL-OD',
        'STUD-OD',
        'INNER-DIAMETER',
        'OUTER-DIAMETER',
    ];

    public const MATERIALS_CODE = 'MATERIALS';

    /**
     * @param list<array<string, mixed>> $specifications
     * @return array{
     *   canGenerate: bool,
     *   sourceHash: string,
     *   values: array<string, array{value: float|string, unit: ?string, source: string, definitionCode: string}>,
     *   missing: list<string>
     * }
     */
    public function resolve(array $specifications): array
    {
        $byCode = [];
        foreach ($specifications as $item) {
            $code = strtoupper(trim(self::str($item['code'] ?? '')));
            if ($code !== '') {
                $byCode[$code] = $item;
            }
        }

        $values = [];
        $missing = [];
        foreach (self::DIMENSION_CODES as $code) {
            $mm = isset($byCode[$code]) ? $this->dimension($code, $byCode[$code]) : null;
            if ($mm === null) {
                $missing[] = $code;
                continue;
            }
            $values[$code] = $mm;
        }

        $materials = isset($byCode[self::MATERIALS_CODE]) ? $this->text($byCode[self::MATERIALS_CODE]) : null;
        if ($materials !== null) {
            $values[self::MATERIALS_CODE] = $materials;
        }

        return [
            'canGenerate' => $values !== [] && array_diff(array_keys($values), [self::MATERIALS_CODE]) !== [],
            'sourceHash' => $this->sourceHash($values),
            'values' => $values,
            'missing' => $missing,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{value: float, unit: string, source: string, definitionCode: string}|null
     */
    private function dimension(string $code, array $item): ?array
    {
        $number = $this->parseNumeric($item['value'] ?? $item['displayValue'] ?? null);
        if ($number === null) {
            return null;
        }
        $mm = $this->toMillimetres($number, isset($item['unit']) ? self::str($item['unit']) : null);
        if ($mm === null || $mm <= 0.0) {
            return null;
        }

        return [
            'value' => $mm,
            'unit' => 'mm',
            'source' => self::str($item['source'] ?? 'product'),
            'definitionCode' => $code,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{value: string, unit: null, source: string, definitionCode: string}|null
     */
    private function text(array $item): ?array
    {
        $text = trim(self::str($item['displayValue'] ?? $item['value'] ?? ''));
        if ($text === '') {
            return null;
        }

        return [
            'value' => $text,
            'unit' => null,
            'source' => self::str($item['source'] ?? 'product'),
            'definitionCode' => self::MATERIALS_CODE,
        ];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function parseNumeric(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (!is_string($raw) || preg_match('/-?\d+(\.\d+)?/', $raw, $m) !== 1) {
            return null;
        }

        return (float) $m[0];
    }

    private function toMillimetres(float $value, ?string $unit): ?float
    {
        if (!is_finite($value) || $value < 0) {
            return null;
        }
        $u = strtolower(trim((string) $unit));
        if ($u === '' || $u === 'mm' || str_contains($u, 'millimet')) {
            return $value;
        }
        if ($u === 'cm' || str_contains($u, 'centimet')) {
            return $value * 10.0;
        }
        if ($u === 'm' || $u === 'metre' || $u === 'metres' || $u === 'meter' || $u === 'meters') {
            return $value * 1000.0;
        }
        if ($u === 'in' || $u === 'inch' || $u === 'inches' || $u === '"') {
            return $value * 25.4;
        }

        // Unknown unit: assume mm (documented).
        return $value;
    }

    /**
     * Stable FNV-1a over sorted CODE=value|unit pairs (same style as the cable mapping).
     *
     * @param array<string, array{value: float|string, unit: ?string, definitionCode: string}> $values
     */
    private function sourceHash(array $values): string
    {
        $parts = [];
        foreach ($values as $entry) {
            $parts[] = strtoupper($entry['definitionCode']) . '=' . (string) $entry['value'] . '|' . (string) ($entry['unit'] ?? '');
        }
        sort($parts);
        $payload = implode(';', $parts);
        $hash = 2166136261;
        $len = strlen($payload);
        for ($i = 0; $i < $len; ++$i) {
            $hash ^= ord($payload[$i]);
            $hash = ($hash * 16777619) & 0xFFFFFFFF;
        }

        return sprintf('fnv1a32:%08x', $hash);
    }
}
