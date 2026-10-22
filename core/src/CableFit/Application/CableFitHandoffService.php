<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\HandoffResult;
use TriCab\Core\Npr\NprRequestSnapshot;

/**
 * Turns NPR Section 5 answers into CableFit design parameters, records
 * partial-import provenance, lists unresolved requirements and detects stale
 * candidates. Pure: no storage, no NPR table access.
 *
 * Ported from npr-portal CableFitHandoffService. Pilot constraints kept:
 *  - no automatic evaluation, fit score or approval;
 *  - Sales-provided values are distinguished from defaults;
 *  - main power cores and additional cores (earth/pilot/fibre) stay separate;
 *  - a candidate is stale once the NPR request is revised past it.
 */
final readonly class CableFitHandoffService
{
    public const MAPPING_VERSION = '1.0';

    private const SUPPORTED_VOLTAGES_KV = [3.3, 6.6, 11.0, 22.0, 33.0];

    /**
     * @param array<string, mixed>|null $chosenAlternatives field => chosen option
     */
    public function buildHandoff(NprRequestSnapshot $request, ?string $variantId = null, ?array $chosenAlternatives = null): HandoffResult
    {
        $variants = array_values(array_filter($request->variants, 'is_array'));
        $answers = $request->section5Answers;

        if ($variants !== []) {
            $selected = null;
            if ($variantId !== null) {
                foreach ($variants as $v) {
                    if ($this->variantId($v, '') === $variantId) {
                        $selected = $v;
                        break;
                    }
                }
            }
            if ($selected === null) {
                $selected = $variants[0];
                $variantId = $this->variantId($selected, 'default');
            }
            $variantAnswers = $selected['answers'] ?? ($selected['answers_json'] ?? []);
            if (is_string($variantAnswers)) {
                $decoded = json_decode($variantAnswers, true);
                $variantAnswers = is_array($decoded) ? $decoded : [];
            }
            /** @var array<string, mixed> $variantAnswers */
            $variantAnswers = is_array($variantAnswers) ? $variantAnswers : [];
            $effective = array_merge($answers, $variantAnswers);
        } else {
            $variantId ??= 'default';
            $effective = $answers;
        }

        $alternatives = $this->detectAlternatives($effective);
        $chosen = $this->resolveChosenAlternatives($alternatives, $chosenAlternatives);
        $mapped = $this->mapSection5ToCableFit($effective, $chosen);

        return new HandoffResult(
            self::MAPPING_VERSION,
            $variantId ?? 'default',
            $mapped['construction'],
            $mapped['provenance'],
            $mapped['unresolvedRequirements'],
            $alternatives,
            $chosen,
        );
    }

    /**
     * A candidate built from NPR revision $builtFromRevision is stale once the
     * request has moved past it.
     */
    public function isStale(?int $builtFromRevision, int $currentRequestRevision): bool
    {
        return $builtFromRevision !== null && $builtFromRevision < $currentRequestRevision;
    }

    public function staleReason(int $builtFromRevision, int $currentRequestRevision): string
    {
        return sprintf(
            'Candidate was generated from NPD Request revision %d, but the request has been amended to revision %d.',
            $builtFromRevision,
            $currentRequestRevision,
        );
    }

    /**
     * @param array<string, mixed> $answers
     * @param array<string, mixed> $chosenChoices
     *
     * @return array{construction: array<string, mixed>, provenance: array<string, mixed>, unresolvedRequirements: list<array<string, mixed>>}
     */
    public function mapSection5ToCableFit(array $answers, array $chosenChoices): array
    {
        $construction = [];
        $provenance = [];
        $unresolved = [];

        // 1. Rated voltage
        $rawVoltage = $this->answerValue($answers, 'voltage_rating');
        if ($rawVoltage !== null) {
            $parsed = $this->parseVoltageRating($rawVoltage);
            if ($parsed['supported']) {
                $construction['systemVoltage_kV'] = $parsed['voltage_kV'];
                $provenance['systemVoltage_kV'] = 'sales_provided';
            } else {
                $valStr = is_array($rawVoltage)
                    ? $this->str($rawVoltage['originalNotation'] ?? json_encode($rawVoltage))
                    : $this->str($rawVoltage);
                $unresolved[] = [
                    'field' => 'voltage_rating',
                    'label' => 'Rated Voltage',
                    'value' => $valStr,
                    'reason' => 'Voltage rating requires manual resolution or is outside standard MV lookup classes (3.3, 6.6, 11, 22, 33 kV).',
                ];
            }
        } else {
            $unresolved[] = [
                'field' => 'voltage_rating',
                'label' => 'Rated Voltage',
                'value' => 'Not provided',
                'reason' => 'No voltage rating provided in Sales submission.',
            ];
        }

        // 2. Main cores and additional cores (strictly separate)
        $mainCores = $this->answerValue($answers, 'main_core_count');
        $additionalCores = $this->answerValue($answers, 'additional_core_requirements');

        $layup = [
            'numberOfCores' => is_numeric($mainCores) ? (int) $mainCores : 1,
            'additionalCoreOrder' => [],
            '_isPartial' => true,
        ];
        $layupProvenance = [
            'numberOfCores' => is_numeric($mainCores) ? 'sales_provided' : 'default',
        ];

        if (is_array($additionalCores) && $additionalCores !== []) {
            $coreOrder = [];
            foreach ($additionalCores as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $type = strtolower($this->str($group['type'] ?? ''));
                $count = (int) $this->num($group['count'] ?? 0);
                if (in_array($type, ['earth', 'pilot', 'fibre'], true)) {
                    for ($i = 0; $i < $count; $i++) {
                        $coreOrder[] = $type;
                    }
                }
            }
            if ($coreOrder !== []) {
                $layup['additionalCoreOrder'] = $coreOrder;
                $layupProvenance['additionalCoreOrder'] = 'sales_provided';
            }
        } elseif (is_string($additionalCores) && $additionalCores !== '') {
            $unresolved[] = [
                'field' => 'additional_core_requirements',
                'label' => 'Additional Cores (Earth/Pilot/Fibre)',
                'value' => $additionalCores,
                'reason' => 'Free-text additional core description requires manual layup configuration.',
            ];
        }
        $construction['layup'] = $layup;
        $provenance['layup'] = $layupProvenance;

        // 3. Conductor
        $condMaterial = $chosenChoices['conductor_material'] ?? $this->answerValue($answers, 'conductor_material');
        if (is_array($condMaterial)) {
            $condMaterial = $condMaterial[0] ?? 'copper';
        }
        $materialStr = strtolower($this->str($condMaterial));
        $csa = $this->answerValue($answers, 'conductor_size');

        $condClassRaw = $chosenChoices['conductor_class'] ?? $this->answerValue($answers, 'conductor_class');
        if (is_array($condClassRaw)) {
            $condClassRaw = $condClassRaw[0] ?? 'class_2';
        }
        $class = $this->parseConductorClass($this->str($condClassRaw));

        $construction['conductor'] = [
            'material' => ($materialStr === 'aluminium' || $materialStr === 'aluminum') ? 'aluminium' : 'copper',
            'conductorClass' => $class['class'],
            'formation' => $class['formation'],
            'csa_mm2' => is_numeric($csa) ? (float) $csa : 70.0,
            '_isPartial' => true,
        ];
        $provenance['conductor'] = [
            'material' => $this->notEmpty($condMaterial) ? 'sales_provided' : 'default',
            'csa_mm2' => is_numeric($csa) ? 'sales_provided' : 'default',
            'conductorClass' => $this->notEmpty($condClassRaw) ? 'sales_provided' : 'default',
            'formation' => 'derived_from_class',
            'numberOfStrands' => 'unconfigured',
            'strandDiameter_mm' => 'unconfigured',
            'layLength_mm' => 'unconfigured',
        ];

        // 4. Insulation
        $insRaw = $chosenChoices['insulation_material'] ?? $this->answerValue($answers, 'insulation_material');
        if (is_array($insRaw)) {
            $insRaw = $insRaw[0] ?? 'XLPE';
        }
        $insStr = strtoupper(trim($this->str($insRaw)));
        if (in_array($insStr, ['XLPE', 'EPR', 'HEPR'], true)) {
            $construction['insulation'] = [
                'material' => $insStr,
                'color' => 'White',
                '_isPartial' => true,
            ];
            $provenance['insulation'] = [
                'material' => 'sales_provided',
                'radialThickness_mm' => 'unconfigured',
                'color' => 'default',
            ];
        } else {
            $unresolved[] = [
                'field' => 'insulation_material',
                'label' => 'Insulation Material',
                'value' => $this->str($insRaw),
                'reason' => 'Material is outside CableFit MV modeled polymers (XLPE, EPR, HEPR). Manual review required.',
            ];
        }

        // 5. Metallic screen
        if (strtolower($this->str($this->answerValue($answers, 'screening_required'))) === 'yes') {
            $screenType = $this->answerValue($answers, 'screen_type');
            $screenStr = $this->str($screenType);
            $mappedScreen = 'COPPER_TAPE';
            if ($this->notEmpty($screenType) && stripos($screenStr, 'wire') !== false) {
                $mappedScreen = 'COPPER_WIRE';
            } elseif ($this->notEmpty($screenType) && stripos($screenStr, 'braid') !== false) {
                $mappedScreen = 'COPPER_BRAID';
            }
            $construction['metallicScreen'] = ['screenType' => $mappedScreen, '_isPartial' => true];
            $provenance['metallicScreen'] = [
                'screenType' => $this->notEmpty($screenType) ? 'sales_provided' : 'default',
                'crossSection_mm2' => 'unconfigured',
                'faultCurrent_kA' => 'unconfigured',
            ];
        }

        // 6. Water blocking
        if (strtolower($this->str($this->answerValue($answers, 'water_blocking_required'))) === 'yes') {
            $construction['waterBlocking'] = ['tapeType' => 'SWELLABLE_TAPE', '_isPartial' => true];
            $provenance['waterBlocking'] = ['tapeType' => 'default', 'thickness_mm' => 'unconfigured'];
        }

        // 7. Armour
        $armourRaw = $chosenChoices['armour'] ?? $this->answerValue($answers, 'armour');
        if (is_array($armourRaw)) {
            $armourRaw = $armourRaw[0] ?? '';
        }
        $armourStr = strtoupper(trim($this->str($armourRaw)));
        if ($armourStr !== '' && $armourStr !== 'NONE') {
            $mappedArmour = 'GALV_STEEL_WIRE';
            if ($armourStr === 'AWA') {
                $mappedArmour = 'ALUMINIUM_WIRE';
            } elseif (stripos($armourStr, 'BRAID') !== false) {
                $mappedArmour = stripos($armourStr, 'COMPOSITE') !== false ? 'COMPOSITE_BRAID' : 'BRAID';
            } elseif (stripos($armourStr, 'TAPE') !== false) {
                $mappedArmour = 'STEEL_TAPE';
            }
            $construction['armour'] = ['armourType' => $mappedArmour, '_isPartial' => true];
            $provenance['armour'] = [
                'armourType' => 'sales_provided',
                'wireDiameter_mm' => 'unconfigured',
                'coverage_percent' => 'unconfigured',
            ];
        }

        // 8. Sheath
        $sheathRaw = $chosenChoices['sheath_material'] ?? $this->answerValue($answers, 'sheath_material');
        if (is_array($sheathRaw)) {
            $sheathRaw = $sheathRaw[0] ?? 'PVC';
        }
        $sheathStr = strtoupper(trim($this->str($sheathRaw)));
        $validSheaths = ['PVC', 'PE', 'LSZH', 'PCP', 'CPE', 'CSP', 'TPU', 'PUR'];
        $sheathValid = in_array($sheathStr, $validSheaths, true);

        $sheathColor = 'Black';
        $rawColours = $this->answerValue($answers, 'sheath_colours');
        if (is_array($rawColours) && $rawColours !== []) {
            $first = ucfirst(strtolower(trim($this->str(reset($rawColours)))));
            if (in_array($first, ['Black', 'Red', 'Orange', 'Yellow'], true)) {
                $sheathColor = $first;
            }
        }
        $construction['sheath'] = [
            'material' => $sheathValid ? $sheathStr : 'PVC',
            'color' => $sheathColor,
            '_isPartial' => true,
        ];
        $provenance['sheath'] = [
            'material' => $sheathValid ? 'sales_provided' : 'default',
            'color' => $this->notEmpty($rawColours) ? 'sales_provided' : 'default',
            'radialThickness_mm' => 'unconfigured',
        ];

        // 9. Non-dimensional requirements kept for engineering review
        $nonDimensional = [
            'operating_temperature' => 'Operating Temperature Limits',
            'fire_performance' => 'Fire & Flame Performance',
            'environmental_conditions' => 'Environmental / Chemical Protection',
            'special_requirements' => 'Special Requirements',
            'standards' => 'Applicable Standards',
            'standards_compliance' => 'Applicable Standards',
            'approvals_required' => 'Statutory & Client Approvals',
            'approval_required' => 'Statutory & Client Approvals',
            'customer_specification' => 'Customer Specification',
            'length_packaging' => 'Delivery Lengths & Packaging',
            'packaging' => 'Packaging',
            'drum_length' => 'Drum Length',
            'cable_marking' => 'Cable Sheath Marking / Legend',
            'marking' => 'Cable Sheath Marking / Legend',
        ];
        foreach ($nonDimensional as $fieldKey => $label) {
            $val = $this->answerValue($answers, $fieldKey);
            $remarks = $this->answerRemarks($answers, $fieldKey);
            if ($val === null && $remarks === null) {
                continue;
            }
            if (is_array($val)) {
                $parts = [];
                foreach ($val as $item) {
                    if (is_array($item)) {
                        if (isset($item['number'])) {
                            $edition = $this->str($item['edition'] ?? '');
                            $parts[] = $this->str($item['number']) . ($edition !== '' ? ':' . $edition : '');
                        } elseif (isset($item['originalText'])) {
                            $parts[] = $this->str($item['originalText']);
                        } else {
                            $parts[] = (string) json_encode($item);
                        }
                    } else {
                        $parts[] = $this->str($item);
                    }
                }
                $valStr = implode(', ', $parts);
            } else {
                $valStr = $val === null ? 'See remarks' : $this->str($val);
            }
            $unresolved[] = [
                'field' => $fieldKey,
                'label' => $label,
                'value' => $valStr,
                'remarks' => $remarks,
                'reason' => 'Special requirement preserved for engineering review (not a mechanical layer input).',
            ];
        }

        $customerNeed = $this->answerValue($answers, 'customer_need');
        if ($customerNeed !== null) {
            $unresolved[] = [
                'field' => 'customer_need',
                'label' => 'Customer Need / Application Context',
                'value' => $this->str($customerNeed),
                'reason' => 'Commercial and application background from Sales.',
            ];
        }

        return [
            'construction' => $construction,
            'provenance' => $provenance,
            'unresolvedRequirements' => $unresolved,
        ];
    }

    /**
     * Section 5 fields that hold several acceptable options.
     *
     * @param array<string, mixed> $answers
     *
     * @return array<string, array<string, mixed>>
     */
    public function detectAlternatives(array $answers): array
    {
        $fields = [
            'conductor_material' => 'Conductor Material',
            'conductor_class' => 'Conductor Class',
            'insulation_material' => 'Insulation Material',
            'armour' => 'Armour Selection',
        ];
        $alternatives = [];
        foreach ($fields as $field => $label) {
            $value = $this->answerValue($answers, $field);
            if (is_array($value) && count($value) > 1) {
                $options = array_values($value);
                $alternatives[$field] = [
                    'field' => $field,
                    'label' => $label,
                    'options' => $options,
                    'default' => $options[0],
                ];
            }
        }

        return $alternatives;
    }

    /**
     * @param array<string, array<string, mixed>> $alternatives
     * @param array<string, mixed>|null           $chosen
     *
     * @return array<string, mixed>
     */
    private function resolveChosenAlternatives(array $alternatives, ?array $chosen): array
    {
        $resolved = [];
        foreach ($alternatives as $field => $meta) {
            $options = is_array($meta['options'] ?? null) ? $meta['options'] : [];
            if ($chosen !== null && isset($chosen[$field]) && in_array($chosen[$field], $options, true)) {
                $resolved[$field] = $chosen[$field];
            } else {
                $resolved[$field] = $meta['default'] ?? null;
            }
        }

        return $resolved;
    }

    /**
     * @return array{supported: bool, voltage_kV: float}
     */
    private function parseVoltageRating(mixed $raw): array
    {
        if (is_array($raw)) {
            if (isset($raw['voltage_kV']) && is_numeric($raw['voltage_kV'])) {
                $num = (float) $raw['voltage_kV'];

                return ['supported' => in_array($num, self::SUPPORTED_VOLTAGES_KV, true), 'voltage_kV' => $num];
            }
            $str = $this->str($raw['originalNotation'] ?? ($raw['value'] ?? ''));
        } else {
            $str = trim($this->str($raw));
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:kV|kv|KV)?/', $str, $m) === 1) {
            $num = (float) $m[1];

            return ['supported' => in_array($num, self::SUPPORTED_VOLTAGES_KV, true), 'voltage_kV' => $num];
        }

        return ['supported' => false, 'voltage_kV' => 11.0];
    }

    /**
     * @return array{class: int, formation: string}
     */
    private function parseConductorClass(string $classStr): array
    {
        $norm = strtolower(trim($classStr));
        if (str_contains($norm, 'solid') || str_contains($norm, 'class 1') || $norm === '1') {
            return ['class' => 1, 'formation' => 'ROUND_STRANDED'];
        }
        if (str_contains($norm, 'class_5') || str_contains($norm, 'class 5') || $norm === '5' || str_contains($norm, 'flexible')) {
            return ['class' => 5, 'formation' => 'ROPE_LAY'];
        }
        if (str_contains($norm, 'class_6') || str_contains($norm, 'class 6') || $norm === '6' || str_contains($norm, 'extra flexible')) {
            return ['class' => 6, 'formation' => 'ROPE_LAY'];
        }

        return ['class' => 2, 'formation' => 'ROUND_STRANDED'];
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function answerValue(array $answers, string $key): mixed
    {
        if (!isset($answers[$key])) {
            return null;
        }
        $item = $answers[$key];
        if (is_array($item)) {
            if (isset($item['state'])) {
                return $item['state'] === 'provided' ? ($item['value'] ?? null) : null;
            }
            if (array_key_exists('value', $item)) {
                return $item['value'];
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function answerRemarks(array $answers, string $key): ?string
    {
        $item = $answers[$key] ?? null;
        if (is_array($item) && isset($item['remarks']) && is_string($item['remarks'])) {
            return $item['remarks'];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $variant
     */
    private function variantId(array $variant, string $fallback): string
    {
        $id = $variant['variantId'] ?? ($variant['variant_id'] ?? ($variant['id'] ?? $fallback));

        return $this->str($id);
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) || $v === null ? (string) $v : '';
    }

    private function num(mixed $v): int|float
    {
        return is_numeric($v) ? $v + 0 : 0;
    }

    /** PHP empty() semantics for answer values. */
    private function notEmpty(mixed $v): bool
    {
        return !empty($v);
    }
}
