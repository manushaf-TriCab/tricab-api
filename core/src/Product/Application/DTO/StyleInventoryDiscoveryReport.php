<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class StyleInventoryDiscoveryReport
{
    /**
     * @param list<StyleInventorySourceStat> $sourceStats
     * @param list<StyleInventoryItem> $items
     * @param array<string, list<string>> $rawVariationsByCanonical
     * @param list<StyleInventoryItem> $targetOnly
     * @param list<StyleInventoryItem> $possibleTestData
     * @param array<string, int> $counters
     * @param list<string> $notes
     * @param array{wordpressWrites: 0, tcWrites: 0, filesystemWrites: 0, azureSqlWrites: 0, blobWrites: 0, persisted: false, forbiddenWriteAttempts: 0} $writeGuard
     */
    public function __construct(
        public array $sourceStats,
        public array $items,
        public array $rawVariationsByCanonical,
        public array $targetOnly,
        public array $possibleTestData,
        public array $counters,
        public bool $extractionSucceeded,
        public bool $canonicalisationDeterministic,
        public int $identityCollisions,
        public bool $readyToCreate,
        public array $notes,
        public array $writeGuard,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceStats' => array_map(static fn (StyleInventorySourceStat $s): array => $s->toArray(), $this->sourceStats),
            'items' => array_map(static fn (StyleInventoryItem $i): array => $i->toArray(), $this->items),
            'rawVariationsByCanonical' => $this->rawVariationsByCanonical,
            'targetOnly' => array_map(static fn (StyleInventoryItem $i): array => $i->toArray(), $this->targetOnly),
            'possibleTestData' => array_map(static fn (StyleInventoryItem $i): array => $i->toArray(), $this->possibleTestData),
            'counters' => $this->counters,
            'extractionSucceeded' => $this->extractionSucceeded,
            'canonicalisationDeterministic' => $this->canonicalisationDeterministic,
            'identityCollisions' => $this->identityCollisions,
            'readyToCreate' => $this->readyToCreate,
            'notes' => $this->notes,
            'writeGuard' => $this->writeGuard,
        ];
    }
}
