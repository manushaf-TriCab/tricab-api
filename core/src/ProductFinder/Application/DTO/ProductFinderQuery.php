<?php

declare(strict_types=1);

namespace TriCab\Core\ProductFinder\Application\DTO;

/**
 * A cable requirement, however the customer chose to state it.
 *
 * Two ways in, and they compose: free text ("4 core 16mm² LSZH marine 3.3 kV"),
 * or the structured parameters a form collects. Where both are given the
 * parameters win, because a customer who typed a number into a labelled box
 * meant that number — the finder's own `FinderRequest.from_payload` resolves it
 * the same way.
 *
 * `colour` accepts a word ("orange") or the two-character segment code ("OR"),
 * and is deliberately not normalised here. `canonical_colour` in
 * `core/python/tricab_cable_rules/normalize.py` is the single alias table, and
 * it earns that: naive two-character truncation maps "grey" to GR, which is
 * *green*. A second copy of those aliases on this side of the hop is the exact
 * divergence `core/python/tools/check_rules_sync.py` exists to prevent.
 */
final readonly class ProductFinderQuery
{
    public function __construct(
        public ?string $text = null,
        public ?int $cableType = null,
        public ?string $style = null,
        public ?int $coreCount = null,
        public ?float $crossSectionMm2 = null,
        public ?string $colour = null,
        public ?float $loadAmps = null,
        public ?float $lengthM = null,
        public ?int $voltage = null,
        public ?int $phase = null,
        public ?int $install = null,
        public ?int $ambient = null,
        public ?string $strategy = null,
        public ?string $region = null,
        public bool $allowAi = true,
    ) {
    }

    /**
     * True when there is nothing to work from. The action rejects these rather
     * than paying a round trip for a guaranteed "needs clarification".
     */
    public function isEmpty(): bool
    {
        return ($this->text === null || trim($this->text) === '')
            && $this->cableType === null
            && ($this->style === null || trim($this->style) === '');
    }

    /**
     * The finder's own request shape. Nulls are dropped rather than sent: the
     * Python side distinguishes "not stated" from a stated zero, and a JSON
     * null would arrive as the latter.
     *
     * The key names are the finder's, not this DTO's, and three of them differ
     * from the property they carry — `cores`, `load` and `length`, read by
     * `FinderRequest.from_payload` in `core/python/product_finder/selection.py`.
     * They were previously sent as `coreCount`, `loadAmps` and `lengthM`, which
     * `from_payload` simply does not look for, so the finder never received a
     * load or a run length and `sizing` came back null on every request through
     * this endpoint. Renaming a key here is a contract change with the finder.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'text' => $this->text,
            'cableType' => $this->cableType,
            'style' => $this->style,
            'cores' => $this->coreCount,
            'crossSection' => $this->crossSectionMm2,
            'colour' => $this->colour,
            'load' => $this->loadAmps,
            'length' => $this->lengthM,
            'voltage' => $this->voltage,
            'phase' => $this->phase,
            'install' => $this->install,
            'ambient' => $this->ambient,
            'strategy' => $this->strategy,
            'region' => $this->region,
            'allowAi' => $this->allowAi,
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
