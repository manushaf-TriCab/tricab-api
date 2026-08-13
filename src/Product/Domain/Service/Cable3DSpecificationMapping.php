<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

/**
 * Maps Product specification definition codes to procedural cable inputs.
 * Never hardcodes SpecificationDefinitionId values.
 *
 * Engineering lengths convert to millimetres (unknown unit ⇒ assume mm for OD codes).
 * Insulation thickness may be derived from NOM-INS-OD − NOM-COND-OD (documented as derived).
 */
final class Cable3DSpecificationMapping
{
    public const OVERALL_DIAMETER_MM = ['NOM-CABLE-OD', 'OUTER_DIAMETER', 'CABLE-OD'];
    public const CORE_COUNT = ['CORES', 'CORE-COUNT', 'CONDUCTOR-COUNT', 'CODE_CORE_COUNT'];
    public const CONDUCTOR_DIAMETER_MM = ['NOM-COND-OD', 'CONDUCTOR-OD'];
    public const INSULATION_OD_MM = ['NOM-INS-OD'];
    public const INSULATION_THICKNESS_MM = ['INSULATION-THICKNESS', 'INS-THICKNESS'];
    public const BEDDING_THICKNESS_MM = ['BEDDING-THICKNESS', 'BEDDING'];
    public const ARMOUR_THICKNESS_MM = ['ARMOUR-THICKNESS', 'ARMOR-THICKNESS', 'ARMOUR'];
    public const OUTER_SHEATH_THICKNESS_MM = ['OUTER-SHEATH-THICKNESS', 'SHEATH-THICKNESS'];
    public const CONDUCTOR_CSA_MM2 = ['CSA'];
    public const SIZE_HINT = ['SIZE'];
    public const MIN_BEND_RADIUS = ['MIN-BEND-RADIUS'];
    public const CONDUCTOR_MATERIAL = ['CODE_CONDUCTOR', 'CONDUCTORS'];
    public const OUTER_SHEATH_COLOUR = ['DEFAULT-COLOUR', 'CODE_JACKET_COLOUR', 'SHEATH'];

    /**
     * @param list<array{code: string, displayValue?: ?string, value?: float|int|string|null, unit?: ?string, source?: string}> $specifications
     * @return array{
     *   detailLevel: string,
     *   canGenerate: bool,
     *   sourceHash: string,
     *   values: array<string, array{value: float|int|string, unit: ?string, source: string, definitionCode: string, displayValue?: ?string}>,
     *   missing: list<string>,
     *   resolved: array<string, string>,
     *   engineering: array<string, mixed>
     * }
     */
    public function resolve(array $specifications): array
    {
        $byCode = [];
        foreach ($specifications as $item) {
            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $byCode[$code] = $item;
        }

        $resolved = [];
        $values = [];

        $overall = $this->firstLengthMm($byCode, self::OVERALL_DIAMETER_MM, $resolved, 'overallDiameterMm');
        if ($overall !== null) {
            $values['overallDiameterMm'] = $overall;
        }

        $coreCount = $this->firstInt($byCode, self::CORE_COUNT, $resolved, 'coreCount');
        if ($coreCount !== null) {
            $hit = $byCode[$resolved['coreCount']];
            $values['coreCount'] = [
                'value' => $coreCount,
                'unit' => null,
                'source' => (string) ($hit['source'] ?? 'product'),
                'definitionCode' => $resolved['coreCount'],
                'displayValue' => $hit['displayValue'] ?? (string) $coreCount,
            ];
        } else {
            $size = $this->firstRaw($byCode, self::SIZE_HINT);
            if ($size !== null) {
                $text = (string) ($size['item']['displayValue'] ?? $size['item']['value'] ?? '');
                $parsed = self::parseCoreCountFromSize($text);
                if ($parsed !== null) {
                    $coreCount = $parsed;
                    $resolved['coreCount'] = $size['code'];
                    $values['coreCount'] = [
                        'value' => $coreCount,
                        'unit' => null,
                        'source' => 'derived',
                        'definitionCode' => $size['code'],
                        'displayValue' => $text,
                    ];
                }
            }
        }

        $condOd = $this->firstLengthMm($byCode, self::CONDUCTOR_DIAMETER_MM, $resolved, 'conductorDiameterMm');
        if ($condOd !== null) {
            $values['conductorDiameterMm'] = $condOd;
        }

        $insOd = $this->firstLengthMm($byCode, self::INSULATION_OD_MM, $resolved, 'insulationOdMm');
        if ($insOd !== null) {
            $values['insulationOdMm'] = $insOd;
        }

        $insTh = $this->firstLengthMm($byCode, self::INSULATION_THICKNESS_MM, $resolved, 'insulationThicknessMm');
        if ($insTh !== null) {
            $values['insulationThicknessMm'] = $insTh;
        } elseif ($condOd !== null && $insOd !== null && (float) $insOd['value'] > (float) $condOd['value']) {
            $derived = ((float) $insOd['value'] - (float) $condOd['value']) / 2.0;
            $resolved['insulationThicknessMm'] = 'derived:INS-FROM-OD';
            $values['insulationThicknessMm'] = [
                'value' => $derived,
                'unit' => 'mm',
                'source' => 'derived',
                'definitionCode' => 'derived:INS-FROM-OD',
                'displayValue' => sprintf('%.3f mm (from OD)', $derived),
            ];
            $insTh = $values['insulationThicknessMm'];
        }

        foreach (
            [
                'beddingThicknessMm' => self::BEDDING_THICKNESS_MM,
                'armourThicknessMm' => self::ARMOUR_THICKNESS_MM,
                'outerSheathThicknessMm' => self::OUTER_SHEATH_THICKNESS_MM,
                'minBendRadiusMm' => self::MIN_BEND_RADIUS,
            ] as $field => $codes
        ) {
            $hit = $this->firstLengthMm($byCode, $codes, $resolved, $field);
            if ($hit !== null) {
                $values[$field] = $hit;
            }
        }

        $csaHit = $this->firstRaw($byCode, self::CONDUCTOR_CSA_MM2);
        if ($csaHit !== null) {
            $num = $this->parseNumeric($csaHit['item']['value'] ?? $csaHit['item']['displayValue'] ?? null);
            if ($num !== null) {
                $resolved['conductorCsaMm2'] = $csaHit['code'];
                $values['conductorCsaMm2'] = [
                    'value' => $num,
                    'unit' => 'mm2',
                    'source' => (string) ($csaHit['item']['source'] ?? 'product'),
                    'definitionCode' => $csaHit['code'],
                    'displayValue' => $csaHit['item']['displayValue'] ?? (string) $num,
                ];
            }
        }

        $material = $this->firstText($byCode, self::CONDUCTOR_MATERIAL, $resolved, 'conductorMaterial');
        if ($material !== null) {
            $values['conductorMaterial'] = $material;
        }

        $colour = $this->firstText($byCode, self::OUTER_SHEATH_COLOUR, $resolved, 'outerSheathColour');
        if ($colour !== null) {
            $code = $colour['definitionCode'];
            $text = (string) $colour['value'];
            $accept = $code !== 'SHEATH' || (bool) preg_match('/^(orange|black|red|blue|grey|gray|white|green|yellow)$/i', $text);
            if ($accept) {
                $values['outerSheathColour'] = $colour;
            } else {
                unset($resolved['outerSheathColour']);
            }
        }

        $engineering = [
            'overallDiameterMm' => isset($values['overallDiameterMm']) ? (float) $values['overallDiameterMm']['value'] : null,
            'coreCount' => $coreCount,
            'conductorDiameterMm' => isset($values['conductorDiameterMm']) ? (float) $values['conductorDiameterMm']['value'] : null,
            'insulationThicknessMm' => isset($values['insulationThicknessMm']) ? (float) $values['insulationThicknessMm']['value'] : null,
            'insulationOdMm' => isset($values['insulationOdMm']) ? (float) $values['insulationOdMm']['value'] : null,
            'beddingThicknessMm' => isset($values['beddingThicknessMm']) ? (float) $values['beddingThicknessMm']['value'] : null,
            'armourThicknessMm' => isset($values['armourThicknessMm']) ? (float) $values['armourThicknessMm']['value'] : null,
            'outerSheathThicknessMm' => isset($values['outerSheathThicknessMm']) ? (float) $values['outerSheathThicknessMm']['value'] : null,
            'conductorCsaMm2' => isset($values['conductorCsaMm2']) ? (float) $values['conductorCsaMm2']['value'] : null,
            'minBendRadiusMm' => isset($values['minBendRadiusMm']) ? (float) $values['minBendRadiusMm']['value'] : null,
            'conductorMaterial' => isset($values['conductorMaterial']) ? (string) $values['conductorMaterial']['value'] : null,
            'outerSheathColour' => isset($values['outerSheathColour']) ? (string) $values['outerSheathColour']['value'] : null,
        ];

        $detailLevel = $this->detailLevel($engineering);
        $missing = [];
        foreach (
            [
                'overallDiameterMm',
                'coreCount',
                'conductorDiameterMm',
                'insulationThicknessMm',
                'beddingThicknessMm',
                'armourThicknessMm',
                'outerSheathThicknessMm',
                'outerSheathColour',
            ] as $field
        ) {
            if ($field === 'insulationThicknessMm') {
                if ($engineering['insulationThicknessMm'] === null && $engineering['insulationOdMm'] === null) {
                    $missing[] = $field;
                }
                continue;
            }
            if ($engineering[$field] === null) {
                $missing[] = $field;
            }
        }

        // Backward-compatible top-level aliases for existing tests
        return [
            'detailLevel' => $detailLevel,
            'canGenerate' => $detailLevel !== 'none',
            'sourceHash' => $this->sourceHash($values),
            'values' => $values,
            'missing' => $missing,
            'resolved' => $resolved,
            'engineering' => $engineering,
            'overallDiameterMm' => $engineering['overallDiameterMm'],
            'conductorCount' => $engineering['coreCount'],
            'conductorDiameterMm' => $engineering['conductorDiameterMm'],
            'insulationOdMm' => $engineering['insulationOdMm'],
            'conductorCsaMm2' => $engineering['conductorCsaMm2'],
            'minBendRadiusMm' => $engineering['minBendRadiusMm'],
        ];
    }

    public static function parseCoreCountFromSize(string $raw): ?int
    {
        if (preg_match('/(\d+)\s*C\b/i', $raw, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];

        return $n > 0 ? $n : null;
    }

    /**
     * @param array<string, mixed> $engineering
     */
    private function detailLevel(array $engineering): string
    {
        $od = $engineering['overallDiameterMm'] ?? null;
        if ($od === null || (float) $od <= 0) {
            return 'none';
        }
        $cores = $engineering['coreCount'] ?? null;
        $cond = $engineering['conductorDiameterMm'] ?? null;
        if ($cores !== null && (int) $cores > 0 && $cond !== null && (float) $cond > 0) {
            $ins = $engineering['insulationThicknessMm'] ?? null;
            $insOd = $engineering['insulationOdMm'] ?? null;
            if (($ins !== null && (float) $ins > 0) || ($insOd !== null && (float) $insOd > 0)) {
                return 'detailed';
            }

            return 'core';
        }

        return 'basic';
    }

    /**
     * @param array<string, array{value: float|int|string, unit: ?string, definitionCode: string}> $values
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

    /**
     * @param array<string, array<string, mixed>> $byCode
     * @param list<string> $codes
     * @return array{item: array<string, mixed>, code: string}|null
     */
    private function firstRaw(array $byCode, array $codes): ?array
    {
        foreach ($codes as $code) {
            if (!isset($byCode[$code])) {
                continue;
            }
            $raw = $byCode[$code]['value'] ?? $byCode[$code]['displayValue'] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }

            return ['item' => $byCode[$code], 'code' => $code];
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $byCode
     * @param list<string> $codes
     * @param array<string, string> $resolved
     * @return array{value: float, unit: string, source: string, definitionCode: string, displayValue: ?string}|null
     */
    private function firstLengthMm(array $byCode, array $codes, array &$resolved, string $field): ?array
    {
        $hit = $this->firstRaw($byCode, $codes);
        if ($hit === null) {
            return null;
        }
        $num = $this->parseNumeric($hit['item']['value'] ?? $hit['item']['displayValue'] ?? null);
        if ($num === null) {
            return null;
        }
        $mm = $this->toMillimetres($num, isset($hit['item']['unit']) ? (string) $hit['item']['unit'] : null);
        if ($mm === null) {
            return null;
        }
        $resolved[$field] = $hit['code'];

        return [
            'value' => $mm,
            'unit' => 'mm',
            'source' => (string) ($hit['item']['source'] ?? 'product'),
            'definitionCode' => $hit['code'],
            'displayValue' => isset($hit['item']['displayValue']) ? (string) $hit['item']['displayValue'] : (string) $num,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byCode
     * @param list<string> $codes
     * @param array<string, string> $resolved
     * @return array{value: string, unit: null, source: string, definitionCode: string, displayValue: string}|null
     */
    private function firstText(array $byCode, array $codes, array &$resolved, string $field): ?array
    {
        $hit = $this->firstRaw($byCode, $codes);
        if ($hit === null) {
            return null;
        }
        $text = trim((string) ($hit['item']['displayValue'] ?? $hit['item']['value'] ?? ''));
        if ($text === '') {
            return null;
        }
        $resolved[$field] = $hit['code'];

        return [
            'value' => $text,
            'unit' => null,
            'source' => (string) ($hit['item']['source'] ?? 'product'),
            'definitionCode' => $hit['code'],
            'displayValue' => $text,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byCode
     * @param list<string> $codes
     * @param array<string, string> $resolved
     */
    private function firstInt(array $byCode, array $codes, array &$resolved, string $field): ?int
    {
        $hit = $this->firstLengthMm($byCode, $codes, $resolved, $field);
        if ($hit === null) {
            return null;
        }

        return (int) round((float) $hit['value']);
    }

    private function parseNumeric(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/-?\d+(\.\d+)?/', $raw, $m) !== 1) {
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
        if ($u === '' || $u === 'mm' || str_contains($u, 'millimet') || str_contains($u, 'millimet')) {
            return $value;
        }
        if ($u === 'cm' || str_contains($u, 'centimet') || str_contains($u, 'centimet')) {
            return $value * 10.0;
        }
        if ($u === 'm' || $u === 'metre' || $u === 'metres' || $u === 'meter' || $u === 'meters') {
            return $value * 1000.0;
        }

        // Unknown unit for diameter codes: assume mm (documented).
        return $value;
    }
}
