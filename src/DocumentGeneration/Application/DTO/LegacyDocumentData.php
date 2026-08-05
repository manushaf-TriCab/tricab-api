<?php

declare(strict_types=1);

namespace TriCab\Core\DocumentGeneration\Application\DTO;

use TriCab\Core\DocumentGeneration\Legacy\LegacyRecord;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;

final readonly class LegacyDocumentData
{
    public function __construct(public LegacyStream $stream)
    {
    }

    public function record(string $type): ?LegacyRecord
    {
        return $this->stream->first($type);
    }

    public function field(string $type, int $index, string $default = ''): string
    {
        return $this->record($type)?->field($index, $default) ?? $default;
    }

    /**
     * @return list<string>
     */
    public function addressLines(string $type, ?int $maximumFieldIndex = null): array
    {
        $record = $this->record($type);
        if ($record === null) {
            return [];
        }

        $last = $maximumFieldIndex ?? count($record->fields) - 1;
        $lines = [];
        for ($index = 1; $index <= $last; ++$index) {
            $value = trim($record->field($index));
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines;
    }

    public function event(string $type, ?string $code = null): ?LegacyRecord
    {
        foreach ($this->stream->all($type) as $event) {
            if ($code === null || trim($event->field(2)) === $code) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return list<array{record: LegacyRecord, comments: list<LegacyRecord>}>
     */
    public function items(): array
    {
        $commentRecords = $this->stream->all('V4203');
        $items = [];

        foreach ($this->stream->all('S009') as $item) {
            $documentId = trim($item->field(0));
            $itemNumber = trim($item->field(1));
            $comments = array_values(array_filter(
                $commentRecords,
                static fn (LegacyRecord $comment): bool =>
                    trim($comment->field(0)) === $documentId
                    && trim($comment->field(1)) === $itemNumber,
            ));

            $items[] = ['record' => $item, 'comments' => $comments];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public function variableLines(string $type, ?string $itemNumber = null): array
    {
        $lines = [];
        foreach ($this->stream->all($type) as $record) {
            if ($itemNumber !== null && trim($record->field(1)) !== $itemNumber) {
                continue;
            }

            foreach (array_slice($record->fields, 3) as $line) {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
        }

        return $lines;
    }
}
