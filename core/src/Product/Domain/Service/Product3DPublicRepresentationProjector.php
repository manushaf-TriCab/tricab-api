<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

/**
 * Projects stored GenerationMetadataJson into a sanitised, customer-safe representation object.
 * Pure: never emits hashes, ids, definition codes, sources or storage fields.
 */
final class Product3DPublicRepresentationProjector
{
    private const ACCURACY_CLASSES = ['visual-preview', 'dimension-verified'];

    /**
     * @param array<string, mixed>|null $metadata
     * @return array{scope: string, accuracyClass: string, sourceVariantCode: ?string, dimensions: list<array{label: string, value: float, unit: string}>, illustrative: list<array{label: string, value: float, unit: string}>}|null
     */
    public function project(?array $metadata, bool $isProductLevel): ?array
    {
        if (!$isProductLevel || $metadata === null) {
            return null;
        }
        $representation = $metadata['representation'] ?? null;
        if (!is_array($representation) || ($representation['scope'] ?? null) !== 'product') {
            return null;
        }
        $accuracy = $metadata['accuracy'] ?? null;
        $class = is_array($accuracy) ? ($accuracy['class'] ?? null) : null;
        if (!is_string($class) || !in_array($class, self::ACCURACY_CLASSES, true)) {
            return null;
        }
        $used = $metadata['dimensionsUsed'] ?? null;
        if (!is_array($used) || $used === []) {
            return null;
        }

        $assumptions = $metadata['assumptions'] ?? [];

        return [
            'scope' => 'product',
            'accuracyClass' => $class,
            'sourceVariantCode' => $this->clean($representation['sourceVariantCode'] ?? null, 80),
            'dimensions' => $this->entries($used),
            'illustrative' => is_array($assumptions) ? $this->entries($assumptions) : [],
        ];
    }

    /**
     * @param array<mixed> $items
     * @return list<array{label: string, value: float, unit: string}>
     */
    private function entries(array $items): array
    {
        $out = [];
        foreach ($items as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $label = $this->clean($entry['label'] ?? null, 60);
            $value = $entry['valueMm'] ?? null;
            if ($label === null || !is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                continue;
            }
            $float = round((float) $value, 3);
            if (!is_finite($float) || $float <= 0.0) {
                continue;
            }
            $out[] = ['label' => $label, 'value' => $float, 'unit' => 'mm'];
        }

        return $out;
    }

    private function clean(mixed $raw, int $max): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $s = strip_tags($raw);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $s);
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        return mb_substr($s, 0, $max);
    }
}
