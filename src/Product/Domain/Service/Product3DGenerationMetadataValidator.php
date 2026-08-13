<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Lightweight allowlist validator for Product3D GenerationMetadataJson.
 */
final class Product3DGenerationMetadataValidator
{
    private const ALLOWED_ROOT = [
        'version',
        'source',
        'style',
        'detailLevel',
        'sourceHash',
        'specificationSnapshot',
        'presentation',
        'geometryStats',
        'units',
        'modelOrigin',
    ];

    /**
     * @param array<string, mixed>|null $metadata
     * @return array{data: array<string, mixed>|null, json: ?string}
     */
    public function validate(?array $metadata): array
    {
        if ($metadata === null) {
            return ['data' => null, 'json' => null];
        }

        $jsonProbe = json_encode($metadata);
        if ($jsonProbe === false) {
            throw InvalidProductValue::for('generationMetadata', 'must be JSON-serialisable');
        }
        if (preg_match('/script|shader|javascript:/i', $jsonProbe) === 1) {
            throw InvalidProductValue::for('generationMetadata', 'contains forbidden content');
        }

        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_ROOT, true)) {
                continue;
            }
            $clean[$key] = $value;
        }

        if ($clean === []) {
            return ['data' => null, 'json' => null];
        }

        return [
            'data' => $clean,
            'json' => json_encode($clean, JSON_THROW_ON_ERROR),
        ];
    }
}
