<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Validates viewer configuration JSON. Rejects executable / shader payloads.
 * Only allowlisted keys are persisted (unknown fields are dropped).
 */
final class Product3DViewerConfigurationValidator
{
    public const DEFAULT_JSON = '{"version":1,"transform":{"scale":1,"position":[0,0,0],"rotation":[0,0,0]},"camera":{"fov":35,"position":[4,2,5],"target":[0,0,0],"minDistance":1,"maxDistance":10},"environment":{"backgroundType":"solid","backgroundColor":"#F4F6F8","preset":"studio"},"lighting":{"preset":"studio-soft","intensity":1},"shadow":{"enabled":true,"opacity":0.25},"controls":{"autoRotate":true,"autoRotateSpeed":0.4,"allowZoom":true,"allowPan":false,"allowRotate":true,"enableFullscreen":true},"display":{"desktopHeight":600,"tabletHeight":500,"mobileHeight":420},"materials":[]}';

    private const TOP_LEVEL = [
        'version',
        'transform',
        'camera',
        'environment',
        'lighting',
        'shadow',
        'controls',
        'display',
        'materials',
    ];

    private const TRANSFORM_KEYS = ['scale', 'position', 'rotation'];
    private const CAMERA_KEYS = ['fov', 'position', 'target', 'minDistance', 'maxDistance'];
    private const ENVIRONMENT_KEYS = ['backgroundType', 'backgroundColor', 'preset'];
    private const LIGHTING_KEYS = ['preset', 'intensity'];
    private const SHADOW_KEYS = ['enabled', 'opacity'];
    private const CONTROLS_KEYS = [
        'autoRotate',
        'autoRotateSpeed',
        'allowZoom',
        'allowPan',
        'allowRotate',
        'enableFullscreen',
    ];
    private const DISPLAY_KEYS = ['desktopHeight', 'tabletHeight', 'mobileHeight'];
    private const MATERIAL_KEYS = ['id', 'name', 'color', 'metalness', 'roughness', 'opacity', 'visible'];

    /**
     * @return array<string, mixed>
     */
    public function defaultConfiguration(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::DEFAULT_JSON, true, 32, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed>|string $input
     * @return array{json: string, data: array<string, mixed>}
     */
    public function validate(array|string $input): array
    {
        if (is_string($input)) {
            $trimmed = trim($input);
            if ($trimmed === '') {
                $data = $this->defaultConfiguration();

                return ['json' => self::encode($data), 'data' => $data];
            }
            try {
                $decoded = json_decode($trimmed, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw InvalidProductValue::for('viewerConfiguration', 'invalid JSON: ' . $exception->getMessage());
            }
            if (!is_array($decoded)) {
                throw InvalidProductValue::for('viewerConfiguration', 'must be a JSON object');
            }
            $input = $decoded;
        }

        $this->assertNoExecutablePayload($input);
        $sanitized = $this->sanitize($input);
        $this->assertNoExecutablePayload($sanitized);

        $version = $sanitized['version'] ?? 1;
        if (!is_int($version) && !(is_string($version) && ctype_digit((string) $version))) {
            throw InvalidProductValue::for('viewerConfiguration.version', (string) $version);
        }
        $version = (int) $version;
        if ($version < 1 || $version > 10) {
            throw InvalidProductValue::for('viewerConfiguration.version', (string) $version);
        }

        $merged = array_replace_recursive($this->defaultConfiguration(), $sanitized);
        $merged['version'] = $version;
        $merged = $this->sanitize($merged);
        $this->assertNoExecutablePayload($merged);

        return ['json' => self::encode($merged), 'data' => $merged];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function sanitize(array $input): array
    {
        $out = [];
        foreach (self::TOP_LEVEL as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            $out[$key] = match ($key) {
                'version' => $value,
                'transform' => is_array($value) ? $this->pick($value, self::TRANSFORM_KEYS) : [],
                'camera' => is_array($value) ? $this->pick($value, self::CAMERA_KEYS) : [],
                'environment' => is_array($value) ? $this->pick($value, self::ENVIRONMENT_KEYS) : [],
                'lighting' => is_array($value) ? $this->pick($value, self::LIGHTING_KEYS) : [],
                'shadow' => is_array($value) ? $this->pick($value, self::SHADOW_KEYS) : [],
                'controls' => is_array($value) ? $this->pick($value, self::CONTROLS_KEYS) : [],
                'display' => is_array($value) ? $this->pick($value, self::DISPLAY_KEYS) : [],
                'materials' => $this->sanitizeMaterials($value),
                default => null,
            };
        }

        return array_filter($out, static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function pick(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sanitizeMaterials(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $materials = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $materials[] = $this->pick($item, self::MATERIAL_KEYS);
        }

        return $materials;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNoExecutablePayload(array $data): void
    {
        $encoded = strtolower(self::encode($data));
        foreach (['<script', 'javascript:', 'onerror=', 'shader', 'eval(', 'function('] as $forbidden) {
            if (str_contains($encoded, $forbidden)) {
                throw InvalidProductValue::for('viewerConfiguration', 'contains forbidden content');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
