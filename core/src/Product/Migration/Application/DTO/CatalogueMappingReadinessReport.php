<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final readonly class CatalogueMappingReadinessReport
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
        public string $fullCatalogueDryRun,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phase' => '12.6',
            'generatedAtUtc' => $this->generatedAtUtc,
            'wordpressConnected' => $this->wordpressConnected,
            'tcConnected' => $this->tcConnected,
            'queriesExecuted' => $this->queriesExecuted,
            'executionSeconds' => $this->executionSeconds,
            'readOnlyGuaranteed' => $this->readOnlyGuaranteed,
            'legacyWrites' => 0,
            'legacySchemaChanges' => 0,
            'productMigration' => 'NO',
            'variantMigration' => 'NO',
            'mediaMigration' => 'NO',
            'pricingMigration' => 'NO',
            'fullCatalogueDryRun' => $this->fullCatalogueDryRun,
            'payload' => $this->payload,
            'sqlLog' => $this->sqlLog,
        ];
    }
}
