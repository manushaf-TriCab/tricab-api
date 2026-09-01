<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Persistence;

use TriCab\Core\CableCalculator\Contract\CableRatingRepository;

final class JsonCableRatingRepository implements CableRatingRepository
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var array<string, array<string, mixed>> */
    private array $byCompositeKey = [];

    /** @var array<string, array<string, mixed>> */
    private array $dcByCompositeKey = [];

    public function __construct(string $jsonPath)
    {
        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)) {
            throw new \RuntimeException('Invalid ratings dataset: ' . $jsonPath);
        }

        $this->rows = $decoded['rows'];
        $this->indexRows();
    }

    public function all(): array
    {
        return $this->rows;
    }

  /** @return array<string, mixed>|null */
    public function findByJoinKey(
        float $size,
        int $ambient,
        int $operating,
        string $config,
        int $coreCount,
        bool $isAluminium,
        bool $dcRating = false,
    ): ?array {
        $key = self::joinKey($size, $ambient, $operating, $config, $coreCount, $isAluminium, $dcRating);

        return ($dcRating ? $this->dcByCompositeKey : $this->byCompositeKey)[$key] ?? null;
    }

    private function indexRows(): void
    {
        foreach ($this->rows as $row) {
            $dc = (string) ($row['dc'] ?? '0') === '1';
            $key = self::joinKey(
                self::normalizeSize((string) ($row['size'] ?? '0')),
                (int) ($row['ambient'] ?? 0),
                (int) ($row['operating'] ?? 0),
                (string) ($row['config'] ?? ''),
                (int) ($row['corecount'] ?? 0),
                strtoupper((string) ($row['ct'] ?? 'C')) === 'A',
                $dc,
            );

            if ($dc) {
                $this->dcByCompositeKey[$key] = $row;
            } else {
                $this->byCompositeKey[$key] = $row;
            }
        }
    }

    public static function joinKey(
        float $size,
        int $ambient,
        int $operating,
        string $config,
        int $coreCount,
        bool $isAluminium,
        bool $dcRating,
    ): string {
        return implode('|', [
            self::formatSize($size),
            (string) $ambient,
            (string) $operating,
            $config,
            (string) $coreCount,
            $isAluminium ? 'A' : 'C',
            $dcRating ? '1' : '0',
        ]);
    }

    public static function normalizeSize(string|float|int $size): float
    {
        return (float) $size;
    }

    public static function formatSize(float $size): string
    {
        if (fmod($size, 1.0) === 0.0) {
            return number_format($size, 2, '.', '');
        }

        return rtrim(rtrim(number_format($size, 4, '.', ''), '0'), '.');
    }
}
