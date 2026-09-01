<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Resources;

/**
 * Describes the versioned cable-calculator JSON dataset under resources/cable-calculator/{version}/.
 */
final readonly class CableCalculatorDatasetManifest
{
    /**
     * @param array<string, mixed> $ratingsMeta
     * @param array<string, mixed> $specsMeta
     */
    public function __construct(
        public string $version,
        public array $ratingsMeta,
        public array $specsMeta,
        public string $ratingsSha256,
        public string $specsSha256,
    ) {
    }

    public static function fromDirectory(string $datasetDirectory): self
    {
        $datasetDirectory = rtrim($datasetDirectory, '/');
        $version = basename($datasetDirectory);

        $ratingsPath = $datasetDirectory . '/ratings.json';
        $specsPath = $datasetDirectory . '/cableSpecs.json';

        if (!is_readable($ratingsPath) || !is_readable($specsPath)) {
            throw new \RuntimeException('Cable calculator dataset files are not readable in ' . $datasetDirectory);
        }

        $ratingsRaw = (string) file_get_contents($ratingsPath);
        $specsRaw = (string) file_get_contents($specsPath);

        $ratingsDecoded = json_decode($ratingsRaw, true);
        $specsDecoded = json_decode($specsRaw, true);

        if (!is_array($ratingsDecoded) || !is_array($specsDecoded)) {
            throw new \RuntimeException('Cable calculator dataset JSON is invalid in ' . $datasetDirectory);
        }

        return new self(
            version: $version,
            ratingsMeta: is_array($ratingsDecoded['meta'] ?? null) ? $ratingsDecoded['meta'] : [],
            specsMeta: is_array($specsDecoded['meta'] ?? null) ? $specsDecoded['meta'] : [],
            ratingsSha256: hash('sha256', $ratingsRaw),
            specsSha256: hash('sha256', $specsRaw),
        );
    }

    public function ratingsRowCount(): int
    {
        return (int) ($this->ratingsMeta['rowCount'] ?? 0);
    }

    public function specsRowCount(): int
    {
        return (int) ($this->specsMeta['rowCount'] ?? 0);
    }

    public function normalizedAt(): ?string
    {
        return isset($this->ratingsMeta['normalizedAt'])
            ? (string) $this->ratingsMeta['normalizedAt']
            : (isset($this->specsMeta['normalizedAt']) ? (string) $this->specsMeta['normalizedAt'] : null);
    }
}
