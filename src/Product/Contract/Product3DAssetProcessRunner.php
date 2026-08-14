<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Runs Node product-3d CLI (analyse / optimise) via argv arrays.
 * Implementations must never shell-interpolate paths.
 *
 * @phpstan-type AnalysisResult array{
 *   ok: bool,
 *   analysis?: array<string, mixed>,
 *   error?: array<string, mixed>
 * }
 * @phpstan-type OptimiseResult array{
 *   ok: bool,
 *   skipped?: bool,
 *   reason?: string,
 *   analysis?: array<string, mixed>,
 *   analysisBefore?: array<string, mixed>,
 *   analysisAfter?: array<string, mixed>,
 *   metadata?: array<string, mixed>,
 *   outputPath?: string,
 *   error?: array<string, mixed>,
 *   durationMs?: int
 * }
 */
interface Product3DAssetProcessRunner
{
    public function isAvailable(): bool;

    /**
     * @return AnalysisResult
     */
    public function analyse(string $inputGlbPath): array;

    /**
     * @return OptimiseResult
     */
    public function optimise(string $inputGlbPath, string $outputGlbPath, string $profile): array;
}
