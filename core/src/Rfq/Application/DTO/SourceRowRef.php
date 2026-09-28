<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * Where in a source document a line came from. Contract `rfq.source-row.v1`.
 *
 * Invariant 3: file, sheet, page and row identity survive save, split and
 * export, so "which cell said this?" always has an answer. Every field but the
 * document is optional because formats differ — a sheet has no page, pasted
 * text has neither — and an absent value is null, never a guessed default.
 *
 * `splitIndex` numbers the lines one source row became (a mains-and-earth row
 * is two lines). Null when the row was not split.
 */
final class SourceRowRef
{
    public function __construct(
        public readonly string $documentPublicId,
        public readonly ?string $sheet,
        public readonly ?int $page,
        public readonly ?int $rowNumber,
        public readonly ?int $splitIndex,
        public readonly string $rawText,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'documentPublicId' => $this->documentPublicId,
            'sheet' => $this->sheet,
            'page' => $this->page,
            'rowNumber' => $this->rowNumber,
            'splitIndex' => $this->splitIndex,
            'rawText' => $this->rawText,
        ];
    }
}
