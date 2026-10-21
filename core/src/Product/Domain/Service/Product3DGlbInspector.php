<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use JsonException;
use TriCab\Core\Product\Domain\Exception\Product3DGlbInvalid;

/**
 * Pure structural inspector for binary glTF (GLB 2.0) files.
 * Does not render or decode geometry; it only validates container and JSON chunk safety.
 */
final class Product3DGlbInspector
{
    /** Files above this size skip deep inspection (header-only check). */
    public const MAX_INSPECT_BYTES = 32 * 1024 * 1024;

    public const HEADER_BYTES = 12;

    private const MAGIC = 'glTF';
    private const CHUNK_JSON = 0x4E4F534A;

    /** Allowed extension names (exact) and prefixes. KHR_lights_punctual is excluded. */
    private const ALLOWED_EXTENSIONS = [
        'KHR_texture_transform',
        'KHR_mesh_quantization',
        'EXT_meshopt_compression',
    ];
    private const ALLOWED_EXTENSION_PREFIX = 'KHR_materials_';

    /**
     * Header-only check (first 12 bytes) used for files too large for deep inspection.
     *
     * @param int|null $totalBytes actual blob length when known
     */
    public function inspectHeader(string $header, ?int $totalBytes = null): void
    {
        if (strlen($header) < self::HEADER_BYTES) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_MAGIC, 'File is too short to be a GLB.');
        }
        if (substr($header, 0, 4) !== self::MAGIC) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_MAGIC, 'File is not a GLB (missing glTF magic).');
        }
        /** @var array{version: int, length: int} $fields */
        $fields = unpack('Vversion/Vlength', substr($header, 4, 8));
        if ($fields['version'] !== 2) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_VERSION, 'Only glTF binary version 2 is supported.');
        }
        if ($totalBytes !== null && $fields['length'] !== $totalBytes) {
            throw Product3DGlbInvalid::because(
                Product3DGlbInvalid::BAD_LENGTH,
                sprintf('GLB header length %d does not match file size %d.', $fields['length'], $totalBytes),
            );
        }
    }

    /**
     * @return array{meshCount: int, extensionsUsed: list<string>, byteLength: int}
     */
    public function inspect(string $binary, int $maxBytes = self::MAX_INSPECT_BYTES): array
    {
        $length = strlen($binary);
        if ($length > $maxBytes) {
            throw Product3DGlbInvalid::because(
                Product3DGlbInvalid::TOO_LARGE,
                sprintf('GLB is %d bytes, above the %d byte inspection limit.', $length, $maxBytes),
            );
        }
        $this->inspectHeader($binary, $length);

        if ($length < self::HEADER_BYTES + 8) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_JSON_CHUNK, 'GLB has no JSON chunk.');
        }
        /** @var array{len: int, type: int} $chunk */
        $chunk = unpack('Vlen/Vtype', substr($binary, self::HEADER_BYTES, 8));
        if ($chunk['type'] !== self::CHUNK_JSON) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_JSON_CHUNK, 'First GLB chunk is not JSON.');
        }
        $start = self::HEADER_BYTES + 8;
        if ($chunk['len'] <= 0 || $start + $chunk['len'] > $length) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_JSON_CHUNK, 'GLB JSON chunk length is invalid.');
        }
        try {
            $json = json_decode(substr($binary, $start, $chunk['len']), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_JSON_CHUNK, 'GLB JSON chunk does not parse.');
        }
        if (!is_array($json)) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::BAD_JSON_CHUNK, 'GLB JSON chunk is not an object.');
        }

        $meshes = $json['meshes'] ?? null;
        if (!is_array($meshes) || $meshes === []) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::NO_MESH, 'GLB contains no meshes.');
        }

        foreach (['buffers', 'images'] as $key) {
            $items = $json[$key] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item) || !array_key_exists('uri', $item)) {
                    continue;
                }
                $uri = $item['uri'];
                if (!is_string($uri) || stripos($uri, 'data:') !== 0) {
                    throw Product3DGlbInvalid::because(
                        Product3DGlbInvalid::EXTERNAL_URI,
                        sprintf('GLB %s entry references an external URI.', $key),
                    );
                }
            }
        }

        $used = $this->stringList($json['extensionsUsed'] ?? []);
        $required = $this->stringList($json['extensionsRequired'] ?? []);
        foreach (array_unique(array_merge($used, $required)) as $extension) {
            if (!self::isAllowedExtension($extension)) {
                throw Product3DGlbInvalid::because(
                    Product3DGlbInvalid::FORBIDDEN_EXTENSION,
                    sprintf('GLB extension "%s" is not allowed.', $extension),
                );
            }
        }

        return [
            'meshCount' => count($meshes),
            'extensionsUsed' => $used,
            'byteLength' => $length,
        ];
    }

    public static function isAllowedExtension(string $extension): bool
    {
        return in_array($extension, self::ALLOWED_EXTENSIONS, true)
            || str_starts_with($extension, self::ALLOWED_EXTENSION_PREFIX);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = is_string($item) ? $item : '__non_string__';
        }

        return $out;
    }
}
