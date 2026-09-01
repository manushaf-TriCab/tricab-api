<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Persistence;

use TriCab\Core\CableCalculator\Contract\CableSpecificationRepository;

final class JsonCableSpecificationRepository implements CableSpecificationRepository
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var array<string, list<array<string, mixed>>> */
    private array $byStyle = [];

    public function __construct(string $jsonPath)
    {
        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)) {
            throw new \RuntimeException('Invalid cable specs dataset: ' . $jsonPath);
        }

        $this->rows = $decoded['rows'];
        $this->indexRows();
    }

    public function all(): array
    {
        return $this->rows;
    }

    /**
     * @param list<string> $styles
     * @return list<array<string, mixed>>
     */
    public function findByStylesAndCores(array $styles, int $cores, bool $isAluminium): array
    {
        $matches = [];
        foreach ($styles as $style) {
            foreach ($this->byStyle[$style] ?? [] as $row) {
                if ((int) ($row['nc'] ?? 0) !== $cores) {
                    continue;
                }

                $ct = strtolower((string) ($row['ct'] ?? ''));
                if ($isAluminium) {
                    if ($ct !== 'a') {
                        continue;
                    }
                } elseif ($ct === 'a') {
                    continue;
                }

                if ((int) ($row['mt'] ?? 0) !== 1301) {
                    continue;
                }

                $matches[] = $row;
            }
        }

        usort(
            $matches,
            static fn (array $a, array $b): int => JsonCableRatingRepository::normalizeSize((string) $a['xa'])
                <=> JsonCableRatingRepository::normalizeSize((string) $b['xa']),
        );

        return $matches;
    }

    private function indexRows(): void
    {
        foreach ($this->rows as $row) {
            $style = (string) ($row['cable_style'] ?? '');
            if ($style === '') {
                continue;
            }

            $this->byStyle[$style][] = $row;
        }
    }
}
