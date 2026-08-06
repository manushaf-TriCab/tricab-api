<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

final class LegacySourceRecord
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $sourceSystem,
        public readonly string $sourceTable,
        public readonly string $sourceIdentifier,
        public readonly string $recordType,
        public readonly array $payload,
        public readonly ?string $parentSourceIdentifier = null,
    ) {
    }

    public function sourceHash(): string
    {
        $json = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $this->sourceSystem . '|' . $this->sourceTable . '|' . $this->sourceIdentifier . '|' . $json);
    }

    public function sourceKey(): string
    {
        return $this->sourceSystem . ':' . $this->sourceTable . ':' . $this->sourceIdentifier;
    }
}
