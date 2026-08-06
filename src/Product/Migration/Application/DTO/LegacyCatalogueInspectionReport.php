<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class LegacyCatalogueInspectionReport
{
    /**
     * @param array<string, mixed> $wordpress
     * @param array<string, mixed> $tc
     * @param list<array<string, mixed>> $sqlLog
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $generatedAtUtc,
        public readonly bool $wordpressConnected,
        public readonly bool $tcConnected,
        public readonly array $wordpress,
        public readonly array $tc,
        public readonly array $sqlLog,
        public readonly array $notes,
        public readonly bool $readOnlyGuaranteed,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generatedAtUtc' => $this->generatedAtUtc,
            'wordpressConnected' => $this->wordpressConnected,
            'tcConnected' => $this->tcConnected,
            'wordpress' => $this->wordpress,
            'tc' => $this->tc,
            'sqlLog' => $this->sqlLog,
            'notes' => $this->notes,
            'readOnlyGuaranteed' => $this->readOnlyGuaranteed,
            'legacyWrites' => 0,
            'legacySchemaChanges' => 0,
        ];
    }
}
