<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

use RuntimeException;

/**
 * Read-only snapshot of legacy tc.cable_specs with the columns a drawing needs
 * (resources/cable-drawing/v1, built by bin/build-cable-drawing-dataset.php).
 */
final class CableDrawingSpecs
{
    /** @var array<string, list<array<string, string>>>|null */
    private ?array $byStyle = null;

    public function __construct(private readonly string $datasetPath)
    {
    }

    /**
     * @return list<array<string, string>>
     */
    public function forStyle(string $style): array
    {
        return $this->index()[strtoupper($style)] ?? [];
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function index(): array
    {
        if ($this->byStyle !== null) {
            return $this->byStyle;
        }

        $decoded = json_decode((string) @file_get_contents($this->datasetPath), true);
        $columns = is_array($decoded) ? ($decoded['columns'] ?? null) : null;
        $rows = is_array($decoded) ? ($decoded['rows'] ?? null) : null;
        if (!is_array($columns) || !is_array($rows)) {
            throw new RuntimeException('Invalid cable drawing dataset: ' . $this->datasetPath);
        }
        $columns = array_map(self::text(...), array_values($columns));

        $byStyle = [];
        foreach ($rows as $values) {
            if (!is_array($values) || count($values) !== count($columns)) {
                continue;
            }
            $row = array_combine($columns, array_map(self::text(...), array_values($values)));
            $byStyle[strtoupper($row['cable_style'] ?? '')][] = $row;
        }

        return $this->byStyle = $byStyle;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
