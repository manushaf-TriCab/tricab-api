<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

/**
 * Synthetic GLB analysis for unit tests / InMemory when Node tooling is unavailable.
 *
 * @phpstan-type AnalysisPayload array<string, mixed>
 */
final class Product3DSyntheticAnalysisBuilder
{
    public const OPTIMISE_MIN_BYTES = 65536;

    /**
     * @param array<string, mixed>|null $generationMetadata
     * @return AnalysisPayload
     */
    public function build(
        ?int $fileSizeBytes,
        ?array $generationMetadata = null,
        ?string $sha256 = null,
    ): array {
        $bytes = $fileSizeBytes ?? 0;
        $geometry = is_array($generationMetadata['geometryStats'] ?? null)
            ? $generationMetadata['geometryStats']
            : [];
        $triangles = isset($geometry['triangles']) ? (int) $geometry['triangles'] : max(1, (int) floor($bytes / 120));
        $vertices = isset($geometry['vertices']) ? (int) $geometry['vertices'] : max(1, (int) floor($triangles * 0.6));
        $meshes = isset($geometry['meshes']) ? (int) $geometry['meshes'] : 1;
        $bbox = is_array($geometry['boundingBox'] ?? null) ? $geometry['boundingBox'] : [
            'min' => [0.0, 0.0, 0.0],
            'max' => [1.0, 1.0, 1.0],
            'size' => [1.0, 1.0, 1.0],
            'center' => [0.5, 0.5, 0.5],
        ];

        $recommendation = $bytes <= self::OPTIMISE_MIN_BYTES ? 'not_required' : 'recommended';
        if ($triangles > 80000 || $bytes > 8 * 1024 * 1024) {
            $recommendation = 'strongly_recommended';
        }

        return [
            'version' => 1,
            'synthetic' => true,
            'fileSizeBytes' => $bytes,
            'sha256' => $sha256,
            'triangles' => $triangles,
            'vertices' => $vertices,
            'meshes' => $meshes,
            'materials' => isset($geometry['materials']) ? (int) $geometry['materials'] : 1,
            'textures' => isset($geometry['textures']) ? (int) $geometry['textures'] : 0,
            'animations' => isset($geometry['animations']) ? (int) $geometry['animations'] : 0,
            'largestTextureDimension' => isset($geometry['largestTextureDimension'])
                ? (int) $geometry['largestTextureDimension']
                : null,
            'boundingBox' => $bbox,
            'extensions' => [
                'meshopt' => false,
                'draco' => false,
                'ktx2' => false,
                'used' => [],
            ],
            'recommendation' => $recommendation,
            'warnings' => [],
        ];
    }
}
