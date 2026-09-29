<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * What the sales grid edits and shows on one line (parity plan E3), as the
 * source's editor reads it off the stored row. Carried on `rfq.line.v1` as
 * `entry`.
 *
 *  - **Quantity as entered:** `cuts` × `lengthEach` metres (`length.number_of_lengths`,
 *    `length.length_each`, after the source's `ensureLengthDefaults`), or, on an
 *    accessory, `cuts` sellable packs of `packSize` (`pack_label`). Decimal strings.
 *  - `quantityHint`: the table cell the quantity came from ("from table: 1375 m").
 *  - `alternatives`: the parser's candidate codes, when it had 2–5.
 *  - `trace`: where the line came from (file, sheet, row) and its split position.
 *  - `directiveExclusion` / `directiveNotes`: what an instruction in the email did to it.
 *  - `manualEntry`: a line a person added, with no source text.
 */
final class LineEntry
{
    /**
     * @param array{header: string, value: string, unit: ?string}|null $quantityHint
     * @param list<string> $alternatives
     * @param array{fileName: ?string, sheet: ?string, rowNumber: ?string, entryIndex: int, splitCount: int} $trace
     * @param list<string> $directiveNotes
     */
    public function __construct(
        public readonly string $cuts,
        public readonly ?string $lengthEach,
        public readonly bool $accessory,
        public readonly int $packSize,
        public readonly string $packLabel,
        public readonly ?array $quantityHint,
        public readonly array $alternatives,
        public readonly array $trace,
        public readonly ?string $directiveExclusion,
        public readonly array $directiveNotes,
        public readonly bool $manualEntry,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cuts' => $this->cuts,
            'lengthEach' => $this->lengthEach,
            'accessory' => $this->accessory,
            'packSize' => $this->packSize,
            'packLabel' => $this->packLabel,
            'quantityHint' => $this->quantityHint,
            'alternatives' => $this->alternatives,
            'trace' => $this->trace,
            'directiveExclusion' => $this->directiveExclusion,
            'directiveNotes' => $this->directiveNotes,
            'manualEntry' => $this->manualEntry,
        ];
    }
}
