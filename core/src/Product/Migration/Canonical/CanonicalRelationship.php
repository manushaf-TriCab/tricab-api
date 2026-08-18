<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Canonical;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class CanonicalRelationship
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $relationshipTypeCode,
        public readonly string $fromSourceKey,
        public readonly string $toSourceKey,
        public readonly string $outcome = 'pending',
        public readonly ?string $message = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'relationshipTypeCode' => $this->relationshipTypeCode,
            'fromSourceKey' => $this->fromSourceKey,
            'toSourceKey' => $this->toSourceKey,
            'outcome' => $this->outcome,
            'message' => $this->message,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceKey: MigrationPayload::string($data['sourceKey'] ?? ''),
            relationshipTypeCode: MigrationPayload::string($data['relationshipTypeCode'] ?? 'related', 'related'),
            fromSourceKey: MigrationPayload::string($data['fromSourceKey'] ?? ''),
            toSourceKey: MigrationPayload::string($data['toSourceKey'] ?? ''),
            outcome: MigrationPayload::string($data['outcome'] ?? 'pending', 'pending'),
            message: MigrationPayload::stringOrNull($data['message'] ?? null),
        );
    }
}
