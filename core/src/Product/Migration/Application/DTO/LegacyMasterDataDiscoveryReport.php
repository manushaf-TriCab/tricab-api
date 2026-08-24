<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final readonly class LegacyMasterDataDiscoveryReport
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $sqlLog
     */
    public function __construct(
        public string $generatedAtUtc,
        public bool $wordpressConnected,
        public bool $tcConnected,
        public array $payload,
        public array $sqlLog,
        public int $queriesExecuted,
        public float $executionSeconds,
        public bool $readOnlyGuaranteed,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generatedAtUtc' => $this->generatedAtUtc,
            'wordpressConnected' => $this->wordpressConnected,
            'tcConnected' => $this->tcConnected,
            'queriesExecuted' => $this->queriesExecuted,
            'executionSeconds' => $this->executionSeconds,
            'readOnlyGuaranteed' => $this->readOnlyGuaranteed,
            'legacyWrites' => 0,
            'legacySchemaChanges' => 0,
            'payload' => $this->payload,
            'sqlLog' => $this->sqlLog,
        ];
    }
}
