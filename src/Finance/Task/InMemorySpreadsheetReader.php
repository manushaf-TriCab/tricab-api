<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\SpreadsheetReader;
use TriCab\Core\Finance\Task\Domain\SpreadsheetSheet;

/**
 * A reader that returns the sheets a test gave it, whatever the bytes are: the importer's rules are tested
 * on this without any file format. `read()` also records what it was asked.
 */
final class InMemorySpreadsheetReader implements SpreadsheetReader
{
    /** @var list<SpreadsheetSheet> */
    public array $sheets = [];

    /** @var list<array{format: string, bytes: int}> */
    public array $reads = [];

    /**
     * @param list<list<mixed>> $rows
     */
    public function withSheet(?string $title, array $rows): self
    {
        $this->sheets[] = new SpreadsheetSheet($title, $rows);

        return $this;
    }

    public function read(string $format, string $content): array
    {
        $this->reads[] = ['format' => $format, 'bytes' => strlen($content)];

        return $this->sheets;
    }
}
